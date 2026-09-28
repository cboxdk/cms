<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Storage\UncoveredRange;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * An in-memory idempotency store for tests (GUARDRAILS 2.3): the committed records and the claims
 * that open transactions hold, shared by the sessions it hands out.
 *
 * The store itself has no transaction, and the IdempotencyStore contract runs only inside one, so
 * the fake of the contract is the session: session() gives a FakeIdempotencySession with
 * begin(), commit() and rollBack(). A claim belongs to the session that made it and is released
 * when that session's transaction ends.
 *
 * PHP runs one session at a time, so a claim that another session holds cannot end while a claim
 * waits for it. whenWaiting() models what happens during the wait instead: it schedules events,
 * such as the holder completing and committing, that happen after a contested claim has waited
 * some milliseconds. A contested claim runs the events due within its budget, in order, until the
 * claim is free; when none is left, it is InFlight. Events after the budget stay scheduled. The
 * wait takes real time, as the contract says: the claim sleeps until each event's time, and an
 * InFlight claim has slept its whole budget. An uncontested claim never sleeps, and the Clock is
 * not moved. holdWhileWaiting() starts a holder in a session of its own and schedules its end
 * with whenWaiting().
 *
 * Expiry reads the clock, so a test moves a FakeClock past the record's expiry to make a key
 * fresh again.
 *
 * Every record date is covered by a partition until uncover() takes a range away. A record's date
 * is the Clock's time, or the changeset's time when that is later, as in the Postgres store.
 * complete() of a record whose date is in an uncovered range then throws PartitionMissing for the
 * table TABLE and records nothing, after the checks of the token.
 */
#[Experimental]
final class FakeIdempotencyStore implements IdempotencyStoreHarness
{
    /** The table PartitionMissing names. */
    public const string TABLE = 'idempotency_keys';

    /** @var list<UncoveredRange> record dates that no partition covers */
    private array $uncovered = [];

    /** @var array<string, FakeIdempotencyRecord> committed records by claim name */
    private array $records = [];

    /** @var array<string, FakeIdempotencySession> the session holding each claim, by claim name */
    private array $claims = [];

    /** @var list<FakeWaitEvent> scheduled wait events, ordered by time */
    private array $events = [];

    public function __construct(private readonly Clock $clock = new FakeClock) {}

    public function session(): FakeIdempotencySession
    {
        return new FakeIdempotencySession($this);
    }

    /**
     * Takes the record dates from $from to $to, both inclusive, out of the partitions.
     */
    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->uncovered[] = new UncoveredRange($from, $to);
    }

    /**
     * Schedules an event for the time a contested claim has waited $afterMilliseconds, for example
     * the session that holds the claim completing it and committing. Events at the same time run
     * in the order they were scheduled.
     *
     * @param  Closure(): void  $event
     */
    public function whenWaiting(int $afterMilliseconds, Closure $event): void
    {
        if ($afterMilliseconds < 0) {
            throw new InvalidArgumentException(sprintf('A wait event happens after 0 or more milliseconds, got %d.', $afterMilliseconds));
        }

        $this->events[] = new FakeWaitEvent($afterMilliseconds, $event);
        usort($this->events, static fn (FakeWaitEvent $a, FakeWaitEvent $b): int => $a->afterMilliseconds <=> $b->afterMilliseconds);
    }

    /**
     * Starts a holder in a session of its own: it begins a transaction, claims the key without
     * waiting and completes the claim with $changesetId when one is given. Its commit or rollback
     * is a wait event at $afterMilliseconds, so it happens once a contested claim has waited that
     * long.
     */
    public function holdWhileWaiting(
        IdempotencyScope $scope,
        IdempotencyKey $key,
        ContentHash $hash,
        ?ChangesetId $changesetId,
        HolderEnd $end,
        int $afterMilliseconds,
    ): void {
        $holder = $this->session();
        $holder->begin();
        $claim = $holder->claim($scope, $key, $hash, WaitBudget::none());

        if (! $claim instanceof Fresh) {
            $holder->rollBack();

            throw new LogicException(sprintf('The holder needs a fresh key, and the claim on [%s] is %s.', self::claimName($scope, $key), $claim::class));
        }

        if ($changesetId instanceof ChangesetId) {
            $holder->complete($claim->token, $changesetId);
        }

        $this->whenWaiting($afterMilliseconds, static function () use ($holder, $end): void {
            match ($end) {
                HolderEnd::Commit => $holder->commit(),
                HolderEnd::RollBack => $holder->rollBack(),
            };
        });
    }

    /**
     * How many wait events are still scheduled.
     */
    public function scheduledWaitEvents(): int
    {
        return count($this->events);
    }

    /**
     * The name a claim and its record are kept under. None of the parts contains a space.
     */
    #[Internal]
    public static function claimName(IdempotencyScope $scope, IdempotencyKey $key): string
    {
        return implode(' ', [$scope->kind->value, $scope->principal->value, $scope->commandType->value, $key->value]);
    }

    /**
     * Takes the claim for the session, waiting within the budget while another session holds it.
     * Returns whether the session holds the claim.
     */
    #[Internal]
    public function acquire(string $name, FakeIdempotencySession $session, WaitBudget $budget): bool
    {
        $waited = 0;

        while (! $this->isFreeFor($name, $session)) {
            $event = $this->nextEventWithin($budget);

            if (! $event instanceof FakeWaitEvent) {
                $this->sleep($budget->milliseconds - $waited);

                return false;
            }

            $this->sleep($event->afterMilliseconds - $waited);
            $waited = max($waited, $event->afterMilliseconds);
            ($event->event)();
        }

        $this->claims[$name] = $session;

        return true;
    }

    #[Internal]
    public function holds(string $name, FakeIdempotencySession $session): bool
    {
        return ($this->claims[$name] ?? null) === $session;
    }

    /**
     * The committed record for the claim, or null when there is none or it has expired.
     */
    #[Internal]
    public function liveRecord(string $name): ?FakeIdempotencyRecord
    {
        $record = $this->records[$name] ?? null;

        return $record instanceof FakeIdempotencyRecord && $record->isLiveAt($this->clock->now()) ? $record : null;
    }

    /**
     * Commits a session's records, replacing expired ones, and releases its claims.
     *
     * @param  array<string, FakeIdempotencyRecord>  $records
     */
    #[Internal]
    public function commitAndRelease(FakeIdempotencySession $session, array $records): void
    {
        foreach ($records as $name => $record) {
            $this->records[$name] = $record;
        }

        $this->release($session);
    }

    /**
     * Releases every claim the session holds.
     */
    #[Internal]
    public function release(FakeIdempotencySession $session): void
    {
        $this->claims = array_filter($this->claims, static fn (FakeIdempotencySession $holder): bool => $holder !== $session);
    }

    /**
     * Throws PartitionMissing when no partition covers the date of a record for the changeset:
     * the Clock's time, or the changeset's time when that is later.
     *
     * @throws PartitionMissing
     */
    #[Internal]
    public function assertCovered(ChangesetId $changesetId): void
    {
        $date = max($this->clock->now(), UncoveredRange::timeOf($changesetId->unixMilliseconds()));

        UncoveredRange::check($this->uncovered, self::TABLE, $date);
    }

    #[Internal]
    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * Sleeps the milliseconds of real time the wait has not taken yet, none when it is not
     * positive.
     */
    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function isFreeFor(string $name, FakeIdempotencySession $session): bool
    {
        $holder = $this->claims[$name] ?? null;

        return $holder === null || $holder === $session;
    }

    /**
     * Removes and returns the first scheduled event due within the budget.
     */
    private function nextEventWithin(WaitBudget $budget): ?FakeWaitEvent
    {
        $event = $this->events[0] ?? null;

        if (! $event instanceof FakeWaitEvent || $event->afterMilliseconds > $budget->milliseconds) {
            return null;
        }

        array_shift($this->events);

        return $event;
    }
}

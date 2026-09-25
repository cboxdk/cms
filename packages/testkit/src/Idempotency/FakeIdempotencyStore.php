<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Closure;
use InvalidArgumentException;

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
 * waits for it. whenWaiting() models the wait budget instead: it schedules events, such as the
 * holder completing and committing, that happen after a contested claim has waited some
 * milliseconds. A contested claim runs the events due within its budget, in order, until the claim
 * is free; when none is left, it is InFlight. Events after the budget stay scheduled. No real time
 * passes, and the Clock is not moved.
 *
 * Expiry reads the clock, so a test moves a FakeClock past the record's expiry to make a key
 * fresh again.
 */
#[Experimental]
final class FakeIdempotencyStore implements IdempotencyStoreHarness
{
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
        return implode(' ', [$scope->kind->value, $scope->principal, $scope->commandType, $key->value]);
    }

    /**
     * Takes the claim for the session, waiting within the budget while another session holds it.
     * Returns whether the session holds the claim.
     */
    #[Internal]
    public function acquire(string $name, FakeIdempotencySession $session, WaitBudget $budget): bool
    {
        while (! $this->isFreeFor($name, $session)) {
            $event = $this->nextEventWithin($budget);

            if (! $event instanceof FakeWaitEvent) {
                return false;
            }

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

    #[Internal]
    public function clock(): Clock
    {
        return $this->clock;
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

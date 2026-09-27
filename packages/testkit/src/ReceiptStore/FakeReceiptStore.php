<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Storage\UncoveredRange;
use Closure;
use DateTimeImmutable;
use LogicException;

/**
 * An in-memory receipt store for tests (GUARDRAILS 2.3).
 *
 * Used directly, it behaves like a connection without a transaction: every call commits at once.
 * session() hands out further connections to the same rows, each with begin(), commit() and
 * rollBack(), so a test can run the transactional cases a database store has. A session's writes
 * inside a transaction are visible to that session only, and to everyone after commit.
 *
 * One receipt per changeset is kept as the Postgres store keeps it: store() first takes a lock on
 * the changeset, then looks for a receipt of either class. Inside a transaction the lock lasts until
 * the transaction ends, also when store() throws. A store of a changeset that another open
 * transaction has stored therefore waits for that transaction, and then throws DuplicateReceipt when
 * it committed, or stores when it rolled back. The duplicate comes from store(), never from
 * commit(), as the contract says.
 *
 * PHP runs one session at a time, so the other transaction cannot end while a store waits for it.
 * whenWaiting() models the wait instead: it schedules events, such as the other session committing
 * or rolling back, that a waiting store runs, in order, until the changeset is free. A store that
 * would wait with no event left throws a LogicException: on Postgres it would wait for ever.
 *
 * Expiry reads the clock, so a test moves a FakeClock past RetentionClass::expiresAt() to expire a
 * Standard receipt.
 *
 * Every changeset time is covered by a partition until uncover() takes a range away. store() of a
 * receipt whose changeset time is in an uncovered range then throws PartitionMissing for the table
 * TABLE and stores nothing, after the check for a duplicate receipt, in the order of the Postgres
 * store.
 */
#[Experimental]
final class FakeReceiptStore implements ReceiptStore, ReceiptStoreHarness
{
    /** The table PartitionMissing names. */
    public const string TABLE = 'receipts';

    /** @var array<string, StoredReceipt> committed receipts by changeset id */
    private array $rows = [];

    /** @var list<UncoveredRange> changeset times that no partition covers */
    private array $uncovered = [];

    /** @var array<string, FakeReceiptSession> the open transaction holding each changeset's lock, by changeset id */
    private array $locks = [];

    /** @var list<Closure(): void> scheduled wait events, in the order they run */
    private array $events = [];

    public function __construct(private readonly Clock $clock = new FakeClock) {}

    public function session(): FakeReceiptSession
    {
        return new FakeReceiptSession($this);
    }

    /**
     * Takes the changeset times from $from to $to, both inclusive, out of the partitions.
     */
    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->uncovered[] = new UncoveredRange($from, $to);
    }

    /**
     * Schedules an event for a store that waits for another open transaction's lock on its
     * changeset, for example that session committing or rolling back. A waiting store runs the
     * events in the order they were scheduled, one after another, until the changeset is free.
     *
     * @param  Closure(): void  $event
     */
    public function whenWaiting(Closure $event): void
    {
        $this->events[] = $event;
    }

    /**
     * How many wait events are still scheduled.
     */
    public function scheduledWaitEvents(): int
    {
        return count($this->events);
    }

    public function store(StoredReceipt $receipt): void
    {
        $this->lock($receipt->changesetId, null);
        $rows = FakeReceiptRows::stored($this->rows, $receipt);
        $this->assertCovered($receipt);
        $this->rows = $rows;
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        return FakeReceiptRows::live($this->rows, $changesetId, $this->clock->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $rows = FakeReceiptRows::marked($this->rows, $changesetId, $status, $this->clock->now());

        if ($rows === null) {
            return false;
        }

        $this->rows = $rows;

        return true;
    }

    /**
     * The committed rows, for a session to read and replay its writes over.
     *
     * @return array<string, StoredReceipt>
     */
    #[Internal]
    public function committedRows(): array
    {
        return $this->rows;
    }

    /**
     * Replaces the committed rows with a session's result at commit.
     *
     * @param  array<string, StoredReceipt>  $rows
     */
    #[Internal]
    public function commitRows(array $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * Takes the changeset's lock for the session's open transaction, or, with null, waits for the
     * lock without keeping it, as a store without a transaction does. While another open
     * transaction holds the lock, it runs the scheduled wait events in order.
     *
     * @throws LogicException when the store would wait and no wait event is left
     */
    #[Internal]
    public function lock(ChangesetId $changesetId, ?FakeReceiptSession $session): void
    {
        $name = $changesetId->toString();

        while (($holder = $this->locks[$name] ?? null) instanceof FakeReceiptSession && $holder !== $session) {
            $event = array_shift($this->events) ?? throw new LogicException(sprintf(
                'Another open transaction stored a receipt for changeset %s, so this store waits until that transaction ends, and no wait event is scheduled: on Postgres it would wait for ever. Schedule what ends that transaction with whenWaiting().',
                $name,
            ));

            $event();
        }

        if ($session instanceof FakeReceiptSession) {
            $this->locks[$name] = $session;
        }
    }

    /**
     * Releases every changeset lock the session's transaction holds, when that transaction ends.
     */
    #[Internal]
    public function unlock(FakeReceiptSession $session): void
    {
        $this->locks = array_filter($this->locks, static fn (FakeReceiptSession $holder): bool => $holder !== $session);
    }

    /**
     * Throws PartitionMissing when no partition covers the receipt's changeset time.
     *
     * @throws PartitionMissing
     */
    #[Internal]
    public function assertCovered(StoredReceipt $receipt): void
    {
        UncoveredRange::check($this->uncovered, self::TABLE, UncoveredRange::timeOf($receipt->changesetId->unixMilliseconds()));
    }

    #[Internal]
    public function clock(): Clock
    {
        return $this->clock;
    }
}

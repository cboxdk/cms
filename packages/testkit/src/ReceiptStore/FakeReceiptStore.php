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
use DateTimeImmutable;

/**
 * An in-memory receipt store for tests (GUARDRAILS 2.3).
 *
 * Used directly, it behaves like a connection without a transaction: every call commits at once.
 * session() hands out further connections to the same rows, each with begin(), commit() and
 * rollBack(), so a test can run the transactional cases a database store has. A session's writes
 * inside a transaction are visible to that session only, and to everyone after commit.
 *
 * The fake does not model lock waits. Where a database would make a second writer wait, the fake
 * lets it continue and applies the writes in commit order; a duplicate receipt that two sessions
 * both stored then fails at the second commit.
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

    public function store(StoredReceipt $receipt): void
    {
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

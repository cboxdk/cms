<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use LogicException;

/**
 * One connection to a FakeReceiptStore. Without a transaction its calls go straight to the shared
 * rows. Inside a transaction its writes are kept as a list and replayed over the committed rows:
 * on every read by this session, and once more at commit, when the result replaces the committed
 * rows. A rollback drops the list.
 *
 * A write is checked when it is made, as a database checks a statement, and again at commit
 * against what other sessions committed meanwhile. A commit that fails there throws the write's
 * error, applies nothing and ends the transaction. Whether a partition covers the changeset is
 * checked only when the write is made: that is when a database routes the row.
 *
 * A PartitionMissing inside a transaction fails it, as it does on Postgres: until rollBack(), every
 * call and commit() throw a LogicException. Postgres would take a COMMIT and roll back instead; the
 * fake refuses it, so a caller that commits after the error shows up in its tests.
 */
#[Experimental]
final class FakeReceiptSession implements ReceiptStore, ReceiptStoreSession
{
    /** @var list<FakeReceiptWrite>|null the writes of the open transaction */
    private ?array $writes = null;

    /** Whether the open transaction met PartitionMissing and takes nothing but a rollback. */
    private bool $failed = false;

    public function __construct(private readonly FakeReceiptStore $database) {}

    public function receipts(): ReceiptStore
    {
        return $this;
    }

    public function begin(): void
    {
        if ($this->writes !== null) {
            throw new LogicException('The session already has a transaction open. Nested transactions and savepoints are forbidden (PRD 4.2).');
        }

        $this->writes = [];
    }

    public function commit(): void
    {
        $writes = $this->writes ?? throw new LogicException('The session has no transaction to commit.');
        $this->refuseWhenFailed();
        $this->writes = null;

        $rows = $this->database->committedRows();

        foreach ($writes as $write) {
            $rows = $write->applyTo($rows);
        }

        $this->database->commitRows($rows);
    }

    public function rollBack(): void
    {
        if ($this->writes === null) {
            throw new LogicException('The session has no transaction to roll back.');
        }

        $this->writes = null;
        $this->failed = false;
    }

    public function inTransaction(): bool
    {
        return $this->writes !== null;
    }

    public function store(StoredReceipt $receipt): void
    {
        $this->refuseWhenFailed();

        if ($this->writes === null) {
            $this->database->store($receipt);

            return;
        }

        FakeReceiptRows::stored($this->rows(), $receipt);

        try {
            $this->database->assertCovered($receipt);
        } catch (PartitionMissing $missing) {
            $this->failed = true;

            throw $missing;
        }

        $this->writes[] = FakeReceiptWrite::store($receipt);
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        $this->refuseWhenFailed();

        return FakeReceiptRows::live($this->rows(), $changesetId, $this->database->clock()->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $this->refuseWhenFailed();

        if ($this->writes === null) {
            return $this->database->markProjection($changesetId, $status);
        }

        $now = $this->database->clock()->now();

        if (FakeReceiptRows::marked($this->rows(), $changesetId, $status, $now) === null) {
            return false;
        }

        $this->writes[] = FakeReceiptWrite::mark($changesetId, $status, $now);

        return true;
    }

    private function refuseWhenFailed(): void
    {
        if ($this->failed) {
            throw new LogicException('The transaction failed with PartitionMissing and takes no further statements. Roll it back.');
        }
    }

    /**
     * The rows this session sees: the committed rows with its own uncommitted writes replayed.
     *
     * @return array<string, StoredReceipt>
     */
    private function rows(): array
    {
        $rows = $this->database->committedRows();

        foreach ($this->writes ?? [] as $write) {
            $rows = $write->applyTo($rows);
        }

        return $rows;
    }
}

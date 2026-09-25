<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\ReceiptStore;
use LogicException;

/**
 * One connection to a FakeReceiptStore. Without a transaction its calls go straight to the shared
 * rows. Inside a transaction its writes are kept as a list and replayed over the committed rows:
 * on every read by this session, and once more at commit, when the result replaces the committed
 * rows. A rollback drops the list.
 *
 * A write is checked when it is made, as a database checks a statement, and again at commit
 * against what other sessions committed meanwhile. A commit that fails there throws the write's
 * error, applies nothing and ends the transaction.
 */
#[Experimental]
final class FakeReceiptSession implements ReceiptStore, ReceiptStoreSession
{
    /** @var list<FakeReceiptWrite>|null the writes of the open transaction */
    private ?array $writes = null;

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
    }

    public function inTransaction(): bool
    {
        return $this->writes !== null;
    }

    public function store(Receipt $receipt): void
    {
        if ($this->writes === null) {
            $this->database->store($receipt);

            return;
        }

        FakeReceiptRows::stored($this->rows(), $receipt);

        $this->writes[] = FakeReceiptWrite::store($receipt);
    }

    public function find(ChangesetId $changesetId): ?Receipt
    {
        return FakeReceiptRows::live($this->rows(), $changesetId, $this->database->clock()->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
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

    /**
     * The rows this session sees: the committed rows with its own uncommitted writes replayed.
     *
     * @return array<string, Receipt>
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

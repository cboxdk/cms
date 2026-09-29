<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Illuminate\Database\Connection;

/**
 * One app-role connection with the caller's transaction control, and the receipt store on it.
 * begin() opens the connection's only transaction; the nested transaction guard fails the test
 * if anything opens a second one.
 */
final readonly class PostgresReceiptSession implements ReceiptStoreSession
{
    public function __construct(
        public Connection $connection,
        private ReceiptStore $store,
    ) {}

    public function receipts(): ReceiptStore
    {
        return $this->store;
    }

    public function position(): CommitPosition
    {
        return ReceiptTables::position($this->connection);
    }

    /**
     * Stores the receipt in the open transaction at its commit position, and returns the receipt
     * as stored.
     */
    public function store(StoredReceipt $receipt): StoredReceipt
    {
        $positioned = ReceiptTables::at($this->connection, $receipt);
        $this->store->store($positioned);

        return $positioned;
    }

    /**
     * Stores the receipts in one transaction on this connection and commits it, and returns them
     * as stored.
     *
     * @return list<StoredReceipt>
     */
    public function storeCommitted(StoredReceipt ...$receipts): array
    {
        return ReceiptTables::commit($this->connection, $this->store, ...$receipts);
    }

    public function begin(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->transactionLevel() > 0;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

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

    /**
     * Stores the receipts in one transaction on this connection and commits it.
     */
    public function storeCommitted(StoredReceipt ...$receipts): void
    {
        ReceiptTables::commit($this->connection, $this->store, ...$receipts);
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

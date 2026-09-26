<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;
use Illuminate\Database\Connection;

/**
 * One app-role connection with the caller's transaction control, and the idempotency store on it.
 * begin() opens the connection's only transaction; the nested transaction guard fails the test if
 * anything opens a second one.
 */
final readonly class PostgresIdempotencySession implements IdempotencyStoreSession
{
    public function __construct(
        public Connection $connection,
        private IdempotencyStore $store,
    ) {}

    public function idempotency(): IdempotencyStore
    {
        return $this->store;
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

<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;

/**
 * One connection for the shared suite: the transaction control of the decorated store's session,
 * and a CountingIdempotencyStore over that session's store.
 */
final readonly class CountingIdempotencySession implements IdempotencyStoreSession
{
    private CountingIdempotencyStore $store;

    public function __construct(private IdempotencyStoreSession $connection)
    {
        $this->store = new CountingIdempotencyStore($connection->idempotency());
    }

    public function idempotency(): CountingIdempotencyStore
    {
        return $this->store;
    }

    public function begin(): void
    {
        $this->connection->begin();
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
        return $this->connection->inTransaction();
    }
}

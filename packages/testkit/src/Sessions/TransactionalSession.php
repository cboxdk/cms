<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Sessions;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One connection to a store under test, with the transaction control the caller has in
 * production: the command kernel opens the transaction, and the stores run inside it (PRD 6.2
 * phase 7). The shared store suites use sessions to show that a store neither begins nor ends a
 * transaction, and that its writes are visible to others only after commit.
 *
 * For a fake a session is an object over shared rows; for a database store it is an independent
 * connection to the same database. A session for a real adapter can serve several stores on the
 * same connection.
 */
#[Experimental]
interface TransactionalSession
{
    /**
     * Opens a transaction on the connection. A transaction that is already open is an error:
     * nested transactions and savepoints are forbidden (PRD 4.2).
     */
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Whether the connection has a transaction open.
     */
    public function inTransaction(): bool;
}

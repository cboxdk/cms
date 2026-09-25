<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\ReceiptStore;

/**
 * One connection to the receipt store under test, with the transaction control the caller has in
 * production: the command kernel opens the transaction, the store runs inside it (PRD 6.2
 * phase 7). The shared suite uses sessions to show that a store neither begins nor ends a
 * transaction and that its writes are visible to others only after commit.
 */
#[Experimental]
interface ReceiptStoreSession
{
    /**
     * The receipt store bound to this session's connection.
     */
    public function receipts(): ReceiptStore;

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

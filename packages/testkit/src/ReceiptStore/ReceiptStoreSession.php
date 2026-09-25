<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;

/**
 * One connection to the receipt store under test, with the caller's transaction control from
 * TransactionalSession. The shared ReceiptStoreContract suite uses sessions to show that a store
 * neither begins nor ends a transaction and that its writes are visible to others only after
 * commit.
 */
#[Experimental]
interface ReceiptStoreSession extends TransactionalSession
{
    /**
     * The receipt store bound to this session's connection.
     */
    public function receipts(): ReceiptStore;
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;
use LogicException;

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

    /**
     * The commit position of the open transaction, the one a receipt stored in it must carry: on
     * Postgres pg_current_xact_id(), which gives the transaction its xid when it has none yet. The
     * same value on every call within one transaction.
     *
     * @throws LogicException when no transaction is open
     */
    public function position(): CommitPosition;
}

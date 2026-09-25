<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What the shared ReceiptStoreContract suite runs against: one receipt store that several sessions
 * reach, each on its own connection. For the FakeReceiptStore a session is an object over shared
 * rows; for a database store it is an independent connection to the same database.
 */
#[Experimental]
interface ReceiptStoreHarness
{
    /**
     * A new session on its own connection, with no transaction open.
     */
    public function session(): ReceiptStoreSession;
}

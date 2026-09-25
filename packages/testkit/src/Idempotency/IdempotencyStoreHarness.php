<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What the shared IdempotencyStoreContract suite runs against: one idempotency store that several
 * sessions reach, each on its own connection, like ReceiptStoreHarness for the receipt store.
 */
#[Experimental]
interface IdempotencyStoreHarness
{
    /**
     * A new session on its own connection, with no transaction open.
     */
    public function session(): IdempotencyStoreSession;
}

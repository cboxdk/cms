<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;

/**
 * One connection to the idempotency store under test, with the caller's transaction control from
 * TransactionalSession. A claim lasts until the session's transaction ends, so the shared
 * IdempotencyStoreContract suite uses two sessions to show claims held, waited for and released.
 */
#[Experimental]
interface IdempotencyStoreSession extends TransactionalSession
{
    /**
     * The idempotency store bound to this session's connection.
     */
    public function idempotency(): IdempotencyStore;
}

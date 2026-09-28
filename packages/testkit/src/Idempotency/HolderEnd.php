<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the holder that IdempotencyStoreHarness::holdWhileWaiting() starts ends its transaction.
 */
#[Experimental]
enum HolderEnd
{
    /** The holder commits, with the record it completed, if any. */
    case Commit;

    /** The holder rolls back, and its record, if any, goes with it. */
    case RollBack;
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;

/**
 * The sessions that held locks on a parent did not finish within the lock timeout, so a
 * DETACH CONCURRENTLY was not started. LockedDdl retries it and, on the last attempt, turns it
 * into LockTimeout.
 */
#[Internal]
final class LockWaitExpired extends RuntimeException {}

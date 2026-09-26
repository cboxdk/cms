<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where a managed partition is in its life, from the catalog.
 */
#[Internal]
enum PartitionState
{
    /** A partition of the table (pg_inherits, not pending detach). */
    case Attached;

    /** DETACH CONCURRENTLY finished its first phase and not its second; FINALIZE completes it. */
    case DetachPending;

    /** A table with a managed name that is no longer a partition: a detach finished and the drop did not. */
    case Detached;
}

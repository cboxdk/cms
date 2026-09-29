<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The stream an event is written to (PRD 7.5). Each stream has its own partitions and each
 * subscription its own cursor per stream; the streams share one transaction horizon.
 */
#[Experimental]
enum EventStream: string
{
    /** Commands from people, agents and the scheduler. */
    case Interactive = 'interactive';

    /** Migrations, backfills, large imports and retention. */
    case Bulk = 'bulk';
}

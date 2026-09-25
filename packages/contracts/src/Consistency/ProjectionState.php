<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where one projection is with a changeset (PRD 8.4: status per affected projection).
 */
#[Experimental]
enum ProjectionState: string
{
    /** The projection has not acknowledged the changeset yet. */
    case Pending = 'pending';

    /** The projection acknowledged the changeset at a known time. */
    case Acknowledged = 'acknowledged';
}

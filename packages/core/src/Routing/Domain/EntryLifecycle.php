<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The lifecycle state of an entry (PRD 6.4): only an active entry is shown to the public (PRD 5.7).
 */
#[Experimental]
enum EntryLifecycle: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Merged = 'merged';
    case Tombstoned = 'tombstoned';
    case Purged = 'purged';
}

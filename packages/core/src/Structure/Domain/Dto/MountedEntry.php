<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;

/**
 * What a mount's source shows of an entry, as mount.set_override reads it past the actor's regions
 * (PRD 5.8, 5.10): the entry, which exists, and a placement of it below the source that is visible
 * at the time read or later, or null when the source shows the entry neither now nor later. The
 * reader answers null instead of this when no entry has the id.
 */
#[Internal]
final readonly class MountedEntry
{
    public function __construct(
        public EntryId $entry,
        public ?PlacementId $visible,
    ) {}
}

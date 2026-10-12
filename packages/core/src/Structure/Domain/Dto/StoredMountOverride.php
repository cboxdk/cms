<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A site's exception to a mount for one entry, as mount.set_override reads it (PRD 5.8): the mount
 * node, the entry, whether the site hides the entry on the mount, and the version of the mount
 * node that set it. An override row exists only while it hides, so one that is read exists to
 * hide; the priority cap of PRD 5.8 is not read until curation sets it.
 */
#[Internal]
final readonly class StoredMountOverride
{
    public function __construct(
        public NodeId $mount,
        public EntryId $entry,
        public bool $hidden,
        public AggregateVersion $version,
    ) {}
}

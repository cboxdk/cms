<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Override;

/**
 * Version 1 of the payload of mount.override_set: the mount node, the entry and whether the site
 * hides the entry on the mount. The node is the mount, the node the entry's slugs are answered
 * under at the mount's URLs, so the invalidation purges its n- key, and the entry's e- key.
 */
#[Experimental]
final readonly class MountOverrideSetV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public EntryId $entry,
        public bool $hidden,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('hidden', EventDatum::boolean($this->hidden))
            ->with('node', EventDatum::identifier($this->node));
    }
}

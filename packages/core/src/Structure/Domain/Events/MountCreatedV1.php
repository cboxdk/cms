<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\NodeId;
use Override;

/**
 * Version 1 of the payload of mount.created: the mount node, the node it was created below and
 * the source whose placements it shows.
 */
#[Experimental]
final readonly class MountCreatedV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public NodeId $source,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('node', EventDatum::identifier($this->node))
            ->with('parent', EventDatum::identifier($this->parent))
            ->with('source', EventDatum::identifier($this->source));
    }
}

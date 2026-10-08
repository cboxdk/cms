<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Override;

/**
 * Version 1 of the payload of node.created: the node, the node it was created below and its kind.
 * It carries no path, which a subscriber reads from the node when it needs the subtree.
 */
#[Experimental]
final readonly class NodeCreatedV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public NodeKind $kind,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('node', EventDatum::identifier($this->node))
            ->with('parent', EventDatum::identifier($this->parent))
            ->with('kind', EventDatum::enum($this->kind));
    }
}

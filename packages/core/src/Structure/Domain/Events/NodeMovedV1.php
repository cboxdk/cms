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
 * Version 1 of the payload of node.moved: the node, the parent it left and the parent it went
 * below. The descendants are not listed: a subscriber that needs them reads the subtree, which
 * moved whole.
 */
#[Experimental]
final readonly class NodeMovedV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public NodeId $from,
        public NodeId $to,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('node', EventDatum::identifier($this->node))
            ->with('from', EventDatum::identifier($this->from))
            ->with('to', EventDatum::identifier($this->to));
    }
}

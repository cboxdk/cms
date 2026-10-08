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
 * Version 1 of the payload of node.archived: the node that became read-only structure.
 */
#[Experimental]
final readonly class NodeArchivedV1 implements EventPayload
{
    public function __construct(public NodeId $node) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()->with('node', EventDatum::identifier($this->node));
    }
}

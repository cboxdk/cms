<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Override;

/**
 * Version 1 of the payload of placement.created: the placement, its entry, its node and its site.
 */
#[Experimental]
final readonly class PlacementCreatedV1 implements EventPayload
{
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public NodeId $node,
        public SiteId $site,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('placement', EventDatum::identifier($this->placement))
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('node', EventDatum::identifier($this->node))
            ->with('site', EventDatum::identifier($this->site));
    }
}

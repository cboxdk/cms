<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Override;

/**
 * Version 1 of the payload of placement.slug_changed: the placement, its entry, the node it sits
 * under (the node its slug is looked up under, so an invalidation reaches the answers of its old
 * and its new URL, PRD 9.4) and the locale. Never a slug, which is text (invariant 10).
 */
#[Experimental]
final readonly class PlacementSlugChangedV1 implements EventPayload
{
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public NodeId $node,
        public Locale $locale,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('placement', EventDatum::identifier($this->placement))
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('node', EventDatum::identifier($this->node))
            ->with('locale', EventDatum::identifier($this->locale));
    }
}

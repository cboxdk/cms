<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Override;

/**
 * Version 1 of the payload of node.route_changed: the node, the site and the language. It carries
 * ids and a locale, never the route, which is text; a subscriber that needs the route reads the
 * node's route on that site in that language (PRD 6.5 invariant 10).
 */
#[Experimental]
final readonly class NodeRouteChangedV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public SiteId $site,
        public Locale $locale,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('node', EventDatum::identifier($this->node))
            ->with('site', EventDatum::identifier($this->site))
            ->with('locale', EventDatum::identifier($this->locale));
    }
}

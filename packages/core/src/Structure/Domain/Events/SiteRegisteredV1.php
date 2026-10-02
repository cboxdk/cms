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
 * Version 1 of the payload of site.registered: the site, its root node and the locales it publishes
 * in, in the order the command gave them.
 */
#[Experimental]
final readonly class SiteRegisteredV1 implements EventPayload
{
    /**
     * @param  list<Locale>  $locales
     */
    public function __construct(
        public SiteId $site,
        public NodeId $root,
        public array $locales,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('site', EventDatum::identifier($this->site))
            ->with('root', EventDatum::identifier($this->root))
            ->with('locales', EventDatum::list(...array_map(EventDatum::identifier(...), $this->locales)));
    }
}

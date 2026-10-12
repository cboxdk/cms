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
 * Version 1 of the payload of site.locale_removed: the site, its root node and the locale.
 */
#[Experimental]
final readonly class SiteLocaleRemovedV1 implements EventPayload
{
    public function __construct(
        public SiteId $site,
        public NodeId $root,
        public Locale $locale,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('site', EventDatum::identifier($this->site))
            ->with('root', EventDatum::identifier($this->root))
            ->with('locale', EventDatum::identifier($this->locale));
    }
}

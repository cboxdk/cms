<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * A site no longer publishes in a locale, site.locale_removed version 1 (PRD 5.9, 7.2): the site at its new
 * version, with its root node and the locale. It carries ids and the locale, never the handle,
 * which is text; a subscriber that needs the handle reads the site. The invalidation purges the
 * root node's key, because what the public resolves below the root in the locale changed.
 */
#[Experimental]
final readonly class SiteLocaleRemoved implements Event
{
    public const string NAME = 'site.locale_removed';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'site';

    public function __construct(
        private int $version,
        private SiteLocaleRemovedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->site, $this->version);
    }

    #[Override]
    public function payload(): SiteLocaleRemovedV1
    {
        return $this->payload;
    }
}

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
 * A site was registered, site.registered version 1 (PRD 5.9, 7.2): the site at version 1, with its
 * root node and its locales. It carries ids and locales, never the handle, which is text; a
 * subscriber that needs the handle reads the site.
 */
#[Experimental]
final readonly class SiteRegistered implements Event
{
    public const string NAME = 'site.registered';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'site';

    public function __construct(
        private int $version,
        private SiteRegisteredV1 $payload,
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
    public function payload(): SiteRegisteredV1
    {
        return $this->payload;
    }
}

<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;

/**
 * An event the search subscriber receives: a page was withdrawn, page.withdrawn version 1.
 */
final readonly class PageWithdrawn implements Event, EventPayload
{
    public function __construct(
        private PageId $page,
        private int $version,
    ) {}

    public static function type(): EventType
    {
        return new EventType('page.withdrawn', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('page'), $this->page, $this->version);
    }

    public function payload(): EventPayload
    {
        return $this;
    }

    public function data(): EventData
    {
        return EventData::empty();
    }
}

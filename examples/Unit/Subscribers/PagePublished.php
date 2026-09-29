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
 * An event the subscribers receive: a page was published, page.published version 1. The payload
 * carries nothing; a subscriber reads the page's state (PRD 7.4).
 */
final readonly class PagePublished implements Event, EventPayload
{
    public function __construct(
        private PageId $page,
        private int $version,
    ) {}

    public static function type(): EventType
    {
        return new EventType('page.published', 1);
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

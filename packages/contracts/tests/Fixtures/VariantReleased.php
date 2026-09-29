<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fixtures;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * An event for the subscription tests, with an empty payload.
 */
final readonly class VariantReleased implements Event, EventPayload
{
    public static function type(): EventType
    {
        return new EventType('variant.released', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('entry'), ChangesetId::fromString('01960000-0000-7000-8000-000000000001'), 1);
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

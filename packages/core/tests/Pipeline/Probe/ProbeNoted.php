<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterId;

/**
 * A test-only event that no subscriber acknowledges on a receipt: a probe was noted,
 * probe.noted version 1.
 */
final readonly class ProbeNoted implements Event, EventPayload
{
    public function __construct(private int $version = 1) {}

    public static function type(): EventType
    {
        return new EventType('probe.noted', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('probe'), new CounterId('probe-1'), $this->version);
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

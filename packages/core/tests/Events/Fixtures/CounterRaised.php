<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Events\Fixtures;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\TextHash;
use DateTimeImmutable;

/**
 * A test-only event: a counter was raised to a new value.
 */
final readonly class CounterRaised implements Event
{
    public function __construct(
        private CounterId $counter,
        private int $version,
        private CounterRaisedV1 $payload,
    ) {}

    /**
     * An event for the counter at the version, with the value $after and fixed other values.
     */
    public static function of(string $counter, int $version, int $after = 1): self
    {
        return new self(new CounterId($counter), $version, new CounterRaisedV1(
            before: $after - 1,
            after: $after,
            label: TextHash::of('A label'),
            state: CounterState::Open,
            at: new DateTimeImmutable('2026-04-01T07:59:00.250000Z'),
            parent: null,
            manual: true,
            linked: [new CounterId('linked-1'), new CounterId('linked-2')],
        ));
    }

    public static function type(): EventType
    {
        return new EventType('counter.raised', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('counter'), $this->counter, $this->version);
    }

    public function payload(): CounterRaisedV1
    {
        return $this->payload;
    }
}

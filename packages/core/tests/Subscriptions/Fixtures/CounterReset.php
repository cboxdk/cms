<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fixtures;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterId;

/**
 * A test-only event of a type the runner's test subscriber does not receive: a counter was reset.
 */
final readonly class CounterReset implements Event
{
    public function __construct(
        private CounterId $counter,
        private int $version,
    ) {}

    public static function of(string $counter, int $version): self
    {
        return new self(new CounterId($counter), $version);
    }

    public static function type(): EventType
    {
        return new EventType('counter.reset', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('counter'), $this->counter, $this->version);
    }

    public function payload(): CounterResetV1
    {
        return new CounterResetV1;
    }
}

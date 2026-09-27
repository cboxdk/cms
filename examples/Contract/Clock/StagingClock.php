<?php

declare(strict_types=1);

namespace Examples\Contract\Clock;

use Cbox\Cms\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The system clock moved forward by a fixed interval, for a staging environment that runs ahead
 * of real time to show what scheduled work will do. It is a clock implementation, so it is the one
 * place that reads the system clock.
 */
final readonly class StagingClock implements Clock
{
    public function __construct(private DateInterval $ahead) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->add($this->ahead);
    }
}

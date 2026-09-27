<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Clock\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The system's wall clock in UTC, with microseconds. The default binding of Clock in the container.
 * It reads the clock on every call and does not depend on the default time zone.
 *
 * It is also a PSR-20 clock, so a library that asks for Psr\Clock\ClockInterface can be given it;
 * both interfaces declare the same now(). The Clock contract itself stays free of PSR-20, because
 * the contracts package depends only on PHP.
 */
#[Experimental]
final readonly class SystemClock implements Clock, ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

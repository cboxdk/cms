<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Clock\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The system's wall clock in UTC, with microseconds. The default binding of Clock in the container.
 * It reads the clock on every call and does not depend on the default time zone.
 */
#[Experimental]
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

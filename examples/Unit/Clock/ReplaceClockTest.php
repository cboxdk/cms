<?php

declare(strict_types=1);

namespace Examples\Unit\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use DateTimeImmutable;
use Examples\Contract\Clock\StagingClock;
use PHPUnit\Framework\Attributes\Test;

final class ReplaceClockTest extends StagingApplicationTestCase
{
    #[Test]
    public function the_container_gives_the_configured_clock_once_per_process(): void
    {
        self::assertSame(app(Clock::class), app(Clock::class));
        self::assertGreaterThan(new DateTimeImmutable('+23 hours'), app(Clock::class)->now());
        self::assertInstanceOf(StagingClock::class, app(Clock::class));
    }

    #[Test]
    public function the_other_contracts_keep_their_defaults_and_read_the_time_from_it(): void
    {
        $before = app(Clock::class)->now();
        $id = app(IdGenerator::class)->next();

        self::assertGreaterThanOrEqual(Uuid7::unixMillisecondsOf($before), $id->unixMilliseconds());
    }
}

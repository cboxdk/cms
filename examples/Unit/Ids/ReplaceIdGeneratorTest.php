<?php

declare(strict_types=1);

namespace Examples\Unit\Ids;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Examples\Contract\Ids\CountingIdGenerator;
use PHPUnit\Framework\Attributes\Test;

final class ReplaceIdGeneratorTest extends CountingApplicationTestCase
{
    #[Test]
    public function the_container_gives_the_configured_generator_once_per_process(): void
    {
        self::assertSame(app(IdGenerator::class), app(IdGenerator::class));
        self::assertInstanceOf(CountingIdGenerator::class, app(IdGenerator::class));
    }

    #[Test]
    public function it_counts_the_ids_that_code_gets_from_the_container(): void
    {
        $first = app(IdGenerator::class)->next();
        $second = app(IdGenerator::class)->next();
        $generator = app()->get(IdGenerator::class);

        self::assertLessThan(0, $first->compareTo($second));
        self::assertInstanceOf(CountingIdGenerator::class, $generator);
        self::assertSame(2, $generator->made());
    }

    #[Test]
    public function the_clock_keeps_its_default(): void
    {
        self::assertInstanceOf(SystemClock::class, app(Clock::class));
    }
}

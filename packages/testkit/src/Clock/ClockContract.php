<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Clock;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for Clock (GUARDRAILS 2.3 and 9). The FakeClock and every real clock
 * run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return the
 * implementation from clock(). The class may extend any test case, so a clock that needs the
 * application or a database can use the Laravel test case and the Postgres harness:
 *
 *     final class SystemClockContractTest extends TestCase
 *     {
 *         use ClockContract;
 *
 *         protected function clock(): Clock
 *         {
 *             return new SystemClock();
 *         }
 *     }
 *
 * The contract does not promise monotonic time, because the wall clock can step back, so there is
 * no case that compares two readings.
 */
#[Experimental]
trait ClockContract
{
    private const string PRECISE = 'Y-m-d\TH:i:s.uP';

    /** Readings taken when looking for microseconds; one is enough unless the clock drops them. */
    private const int READINGS = 5;

    /**
     * A new instance of the clock under test.
     */
    abstract protected function clock(): Clock;

    #[Test]
    public function now_is_in_the_utc_time_zone(): void
    {
        $now = $this->clock()->now();

        Assert::assertSame('UTC', $now->getTimezone()->getName());
        Assert::assertSame(0, $now->getOffset());
    }

    #[Test]
    public function now_is_in_utc_whatever_the_default_time_zone(): void
    {
        $default = date_default_timezone_get();

        try {
            foreach (['Pacific/Chatham', 'America/St_Johns', 'Europe/Copenhagen'] as $zone) {
                date_default_timezone_set($zone);

                $now = $this->clock()->now();

                Assert::assertSame('UTC', $now->getTimezone()->getName(), "With the default time zone {$zone}.");
                Assert::assertSame(0, $now->getOffset(), "With the default time zone {$zone}.");
            }
        } finally {
            date_default_timezone_set($default);
        }
    }

    #[Test]
    public function now_is_an_immutable_value(): void
    {
        $now = $this->clock()->now();
        $before = $now->format(self::PRECISE);

        $later = $now->add(new DateInterval('P1D'));
        $modified = $now->modify('+1 hour');
        $moved = $now->setTimezone(new DateTimeZone('Europe/Copenhagen'));
        $changed = $now->setTime(0, 0);

        Assert::assertInstanceOf(DateTimeImmutable::class, $now);
        Assert::assertSame($before, $now->format(self::PRECISE));
        Assert::assertSame('UTC', $now->getTimezone()->getName());

        foreach ([$later, $modified, $moved, $changed] as $copy) {
            Assert::assertNotSame($now, $copy);
        }
    }

    #[Test]
    public function now_keeps_microseconds(): void
    {
        $clock = $this->clock();
        $seen = [];

        for ($reading = 0; $reading < self::READINGS; $reading++) {
            $microseconds = (int) $clock->now()->format('u');
            $seen[] = $microseconds;

            // A clock that keeps microseconds shows a value that is not a whole millisecond.
            if ($microseconds % 1000 !== 0) {
                Assert::assertGreaterThan(0, $microseconds);

                return;
            }

            usleep(7);
        }

        Assert::fail(sprintf(
            'The clock dropped its microseconds: %d readings were whole milliseconds (%s).',
            self::READINGS,
            implode(', ', $seen),
        ));
    }
}

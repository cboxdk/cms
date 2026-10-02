<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * StopwatchBehaviour against the stopwatch the application times hooks with, over real waits.
 */
final class HrtimeStopwatchBehaviourTest extends TestCase
{
    use StopwatchBehaviour;

    #[Override]
    protected function stopwatch(): Stopwatch
    {
        return new HrtimeStopwatch;
    }

    #[Test]
    public function a_reading_is_the_monotonic_timer_of_php_in_nanoseconds(): void
    {
        $before = hrtime(true);
        $reading = new HrtimeStopwatch()->nanoseconds();
        $after = hrtime(true);

        self::assertGreaterThanOrEqual($before, $reading);
        self::assertLessThanOrEqual($after, $reading);
    }

    #[Override]
    protected function pass(Stopwatch $stopwatch, int $nanoseconds): void
    {
        usleep(intdiv($nanoseconds, 1_000) + 1);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every Stopwatch does, run against HrtimeStopwatch and FakeStopwatch (GUARDRAILS 9): a
 * reading never runs backwards, and the time that passes between two readings is their
 * difference in nanoseconds.
 */
trait StopwatchBehaviour
{
    abstract protected function stopwatch(): Stopwatch;

    /**
     * Lets at least the given time pass for the stopwatch under test.
     */
    abstract protected function pass(Stopwatch $stopwatch, int $nanoseconds): void;

    #[Test]
    public function a_reading_never_runs_backwards(): void
    {
        $stopwatch = $this->stopwatch();
        $previous = $stopwatch->nanoseconds();

        foreach (range(1, 100) as $ignored) {
            $reading = $stopwatch->nanoseconds();
            Assert::assertGreaterThanOrEqual($previous, $reading);
            $previous = $reading;
        }
    }

    #[Test]
    public function the_difference_of_two_readings_is_the_time_that_passed_in_nanoseconds(): void
    {
        $stopwatch = $this->stopwatch();
        $started = $stopwatch->nanoseconds();
        $this->pass($stopwatch, 2_000_000);
        $elapsed = $stopwatch->nanoseconds() - $started;

        Assert::assertGreaterThanOrEqual(2_000_000, $elapsed);
        Assert::assertLessThan(2_000_000_000, $elapsed);
    }
}

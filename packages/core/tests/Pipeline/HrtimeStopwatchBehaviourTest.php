<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Override;
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

    #[Override]
    protected function pass(Stopwatch $stopwatch, int $nanoseconds): void
    {
        usleep(intdiv($nanoseconds, 1_000) + 1);
    }
}

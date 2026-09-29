<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Override;

/**
 * A stopwatch that stands still until a test, or a test hook, advances it, so a hook's time is
 * exactly what the test says it is.
 */
final class FakeStopwatch implements Stopwatch
{
    public function __construct(private int $nanoseconds = 0) {}

    #[Override]
    public function nanoseconds(): int
    {
        return $this->nanoseconds;
    }

    public function advance(int $nanoseconds): void
    {
        $this->nanoseconds += $nanoseconds;
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        $this->advance($milliseconds * 1_000_000);
    }
}

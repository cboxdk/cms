<?php

declare(strict_types=1);

namespace Examples\Contract\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\ClockContract;
use DateInterval;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Clock suite against the application's own clock. The trait brings the cases; the
 * class only says how to make the clock.
 */
final class StagingClockContractTest extends TestCase
{
    use ClockContract;

    #[Override]
    protected function clock(): Clock
    {
        return new StagingClock(new DateInterval('P1D'));
    }
}

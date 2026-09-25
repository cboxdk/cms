<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Cbox\Cms\Testkit\Clock\ClockContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Clock contract suite against the system clock.
 */
final class SystemClockContractTest extends TestCase
{
    use ClockContract;

    #[Override]
    protected function clock(): Clock
    {
        return new SystemClock;
    }
}

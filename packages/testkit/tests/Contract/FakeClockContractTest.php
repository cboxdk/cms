<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\ClockContract;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Clock contract suite against the fake clock, as tests get it: new, at its start.
 */
final class FakeClockContractTest extends TestCase
{
    use ClockContract;

    #[Override]
    protected function clock(): Clock
    {
        return new FakeClock;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\ClockContract;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite with the clock under test injected.
 */
final class InjectedClockContract extends TestCase
{
    use ClockContract;

    public ?Clock $subject = null;

    #[Override]
    protected function clock(): Clock
    {
        return $this->subject ?? throw new LogicException('No clock was injected.');
    }
}

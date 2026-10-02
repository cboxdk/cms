<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Signals\BackChannelLogoutContract;
use Cbox\Cms\Testkit\Signals\BackChannelLogoutHarness;
use Closure;
use Override;

/**
 * BackChannelLogoutContract against the receivers a closure plants.
 */
final readonly class LogoutSuite implements BackChannelLogoutHarness
{
    use BackChannelLogoutContract;

    /**
     * @param  Closure(Clock, SignalPin ...): BackChannelLogoutReceiver  $plant
     */
    public function __construct(private Closure $plant) {}

    #[Override]
    public function receiver(Clock $clock, SignalPin ...$pins): BackChannelLogoutReceiver
    {
        return ($this->plant)($clock, ...$pins);
    }

    #[Override]
    protected function logouts(): BackChannelLogoutHarness
    {
        return $this;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;

/**
 * What the shared suite SecurityEventReceiverContract needs: a receiver with no jti remembered, that
 * reads the time from the given clock and takes events for exactly the given pins.
 */
#[Experimental]
interface SecurityEventHarness
{
    public function receiver(Clock $clock, SignalPin ...$pins): SecurityEventReceiver;
}

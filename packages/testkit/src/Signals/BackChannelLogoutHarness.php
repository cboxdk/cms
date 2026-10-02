<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;

/**
 * What the shared suite BackChannelLogoutContract needs: a receiver with no jti remembered, that
 * reads the time from the given clock and takes logouts for exactly the given pins. A harness for a
 * real receiver writes the pins where it reads its connections and gives it an empty replay store.
 */
#[Experimental]
interface BackChannelLogoutHarness
{
    public function receiver(Clock $clock, SignalPin ...$pins): BackChannelLogoutReceiver;
}

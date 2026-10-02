<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Signals\SecurityEventHarness;
use Cbox\Cms\Testkit\Signals\SecurityEventReceiverContract;
use Closure;
use Override;

/**
 * SecurityEventReceiverContract against the receivers a closure plants.
 */
final readonly class SecurityEventSuite implements SecurityEventHarness
{
    use SecurityEventReceiverContract;

    /**
     * @param  Closure(Clock, SignalPin ...): SecurityEventReceiver  $plant
     */
    public function __construct(private Closure $plant) {}

    #[Override]
    public function receiver(Clock $clock, SignalPin ...$pins): SecurityEventReceiver
    {
        return ($this->plant)($clock, ...$pins);
    }

    #[Override]
    protected function events(): SecurityEventHarness
    {
        return $this;
    }
}

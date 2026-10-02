<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventOutcome;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Signals\SignalPins;
use Override;

/**
 * A planted receiver that applies every rule of the pin but remembers no jti, so a retried
 * delivery of an event is applied again.
 */
final readonly class ReappliesEvents implements SecurityEventReceiver
{
    private SignalPins $pins;

    public function __construct(private Clock $clock, SignalPin ...$pins)
    {
        $this->pins = new SignalPins(...$pins);
    }

    #[Override]
    public function receive(ConnectionId $connection, SecurityEventToken $token): SecurityEventOutcome
    {
        return $this->pins->of($connection)->admitEvent($token, $this->clock->now());
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\LogoutOutcome;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Signals\SignalPins;
use Override;

/**
 * A planted receiver that applies every rule of the pin but remembers no jti, so a replayed logout
 * token is admitted again.
 */
final readonly class ForgetsLogouts implements BackChannelLogoutReceiver
{
    private SignalPins $pins;

    public function __construct(private Clock $clock, SignalPin ...$pins)
    {
        $this->pins = new SignalPins(...$pins);
    }

    #[Override]
    public function receive(ConnectionId $connection, LogoutToken $token): LogoutOutcome
    {
        return $this->pins->of($connection)->admitLogout($token, $this->clock->now());
    }
}

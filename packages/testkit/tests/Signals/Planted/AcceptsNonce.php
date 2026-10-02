<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\LogoutOutcome;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Signals\FakeBackChannelLogoutReceiver;
use Override;

/**
 * A planted receiver that does not look at the nonce: it hands the fake the token as if it carried
 * none, so an ID token sent as a logout token ends sessions.
 */
final readonly class AcceptsNonce implements BackChannelLogoutReceiver
{
    private FakeBackChannelLogoutReceiver $fake;

    public function __construct(Clock $clock, SignalPin ...$pins)
    {
        $this->fake = new FakeBackChannelLogoutReceiver($clock, ...$pins);
    }

    #[Override]
    public function receive(ConnectionId $connection, LogoutToken $token): LogoutOutcome
    {
        return $this->fake->receive($connection, new LogoutToken(
            $token->issuer,
            $token->audience,
            $token->issuedAt,
            $token->expiresAt,
            $token->jti,
            $token->events,
            $token->subject,
            $token->session,
        ));
    }
}

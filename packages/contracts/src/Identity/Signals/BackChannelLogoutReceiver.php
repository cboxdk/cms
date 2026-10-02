<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;

/**
 * Receives a back-channel logout of OpenID Connect Back-Channel Logout 1.0 for a connection (PRD
 * 5.16, "Signaler fra IdP'en"). The endpoint verifies the logout token's signature against the
 * issuer's keys first and builds a LogoutToken from it; receive() decides the rest through
 * SignalPin::admitLogout() at the Clock's time and then remembers the issuer and the jti, so a
 * replayed jti is refused as signal_replayed. Remembering the jti is part of the call: a jti is
 * remembered only when receive() admits the token, and at least until the token expires.
 *
 * The outcome ends sessions only, never through a command, and the endpoint answers 200; a refusal
 * is 400. The identity module's implementation comes with the OpenID Connect implementation (B1
 * part 2).
 */
#[Experimental]
interface BackChannelLogoutReceiver
{
    /**
     * @throws UnknownConnection when no pin names the connection
     * @throws SignalRefused when the token breaks a rule or its jti was received before
     */
    public function receive(ConnectionId $connection, LogoutToken $token): LogoutOutcome;
}

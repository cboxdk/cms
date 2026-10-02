<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;

/**
 * Receives a security event of the OpenID Shared Signals Framework 1.0 for a connection (PRD 5.16,
 * "Signaler fra IdP'en"), delivered by push (RFC 8935) or poll (RFC 8936): CAEP session-revoked and
 * credential-change, and RISC account-disabled, account-enabled, account-purged and
 * credential-compromise. The endpoint or poller verifies the token's signature first and builds a
 * SecurityEventToken from it; receive() decides the rest through SignalPin::admitEvent() at the
 * Clock's time.
 *
 * The jti is idempotent: the first admitted delivery of a jti from its issuer is
 * SecurityEventApplied, and every later one is SecurityEventReplayed, which the caller acknowledges
 * and does nothing for. A jti is remembered only when receive() admits the token. The identity
 * module's implementation comes with B6.
 */
#[Experimental]
interface SecurityEventReceiver
{
    /**
     * @throws UnknownConnection when no pin names the connection
     * @throws SignalRefused when the token breaks a rule
     */
    public function receive(ConnectionId $connection, SecurityEventToken $token): SecurityEventOutcome;
}

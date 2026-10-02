<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;

/**
 * What a SecurityEventReceiver answers for a security event it accepts (PRD 5.16): either
 * SecurityEventApplied, the first time the event's jti arrives from its issuer, which carries what
 * the core does, or SecurityEventReplayed, every later time, which carries nothing to do. Both are
 * acknowledged to the transmitter, so a replay has one effect, and the transmitter stops sending it.
 */
#[Experimental]
interface SecurityEventOutcome
{
    public function connection(): ConnectionId;

    public function jti(): SignalId;
}

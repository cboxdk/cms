<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Override;

/**
 * A security event whose jti arrived before from the same issuer (PRD 5.16): it is acknowledged
 * again, and the caller does nothing, so a transmitter that retries a delivery never applies an
 * event twice.
 */
#[Experimental]
final readonly class SecurityEventReplayed implements SecurityEventOutcome
{
    public function __construct(public ConnectionId $connection, public SignalId $jti) {}

    #[Override]
    public function connection(): ConnectionId
    {
        return $this->connection;
    }

    #[Override]
    public function jti(): SignalId
    {
        return $this->jti;
    }
}

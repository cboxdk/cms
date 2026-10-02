<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Override;

/**
 * A security event admitted for the first time (PRD 5.16): its kind and the IdP identity it names.
 * The caller finds the actor of the identity and does action(): it ends the actor's sessions, or
 * issues the command, with the jti in the command's unit of work. An identity no actor has is
 * acknowledged and does nothing.
 */
#[Experimental]
final readonly class SecurityEventApplied implements SecurityEventOutcome
{
    public function __construct(
        public ConnectionId $connection,
        public SignalId $jti,
        public SecurityEventKind $kind,
        public IdpIdentity $subject,
    ) {}

    public function action(): SignalAction
    {
        return $this->kind->action();
    }

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

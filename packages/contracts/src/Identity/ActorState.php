<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The state of an actor (PRD 5.16, 6.4). Only an active actor has a credential that verifies,
 * runs a command or reads (invariant 37).
 */
#[Experimental]
enum ActorState: string
{
    /** Registered, but its credential is not written yet. */
    case Pending = 'pending';

    case Active = 'active';

    /** Can be reactivated under the rules of PRD 5.16. */
    case Deactivated = 'deactivated';

    /** Final: the link to the IdP is removed. The actor stays as a subject. */
    case Deprovisioned = 'deprovisioned';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether entering this state counts the actor's credential generation up, so every credential
     * it holds is refused at once (PRD 5.16, 6.4).
     */
    public function revokesCredentials(): bool
    {
        return $this === self::Deactivated || $this === self::Deprovisioned;
    }
}

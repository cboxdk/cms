<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What deactivated an actor, which actor.deactivate notes on the actor (PRD 5.16): the rules for
 * reactivating it depend on it. The values are what the actors table and the event actor.deactivated
 * hold.
 *
 * A deactivation by one of the IdP's connections or by SSF names the connection and comes with the
 * connections themselves (block B6).
 */
#[Experimental]
enum DeactivationSource: string
{
    /** A local command, such as an administrator's; reactivation needs four eyes and step-up. */
    case Local = 'local';

    /** The automatic rule for staff without a successful login or renewal within the policy's period. */
    case Inactivity = 'inactivity';
}

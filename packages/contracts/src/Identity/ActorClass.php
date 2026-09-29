<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The class of an actor (PRD 5.16). It is set when the actor is created and never changes; a
 * person who is both a reader and a member of staff has two actors.
 */
#[Experimental]
enum ActorClass: string
{
    /** Editors, developers and administrators, who log in through the staff connections. */
    case Staff = 'staff';

    /** Readers and members, who log in through the end-user connections. */
    case EndUser = 'end_user';

    /**
     * Agents, integrations, sidecars, addons and IdP connections. A service actor never logs in:
     * it only holds service credentials, and each has a named person responsible for it.
     */
    case Service = 'service';
}

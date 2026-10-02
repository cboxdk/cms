<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Which sessions a back-channel logout ends (PRD 5.16).
 */
#[Experimental]
enum LogoutScope: string
{
    /** The sessions of the IdP session the token's sid names, through the set of (connection, sid). */
    case IdpSession = 'idp_session';

    /** Every session of the subject's actor, through the actor's set, when the token names no sid. */
    case Subject = 'subject';
}

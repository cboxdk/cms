<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Why the login policy refused a login (PRD 5.16, invariant 38). Each is a code of the error
 * catalog, Cbox\Cms\Contracts\Errors\ErrorCode.
 */
#[Internal]
enum LoginPolicyErrorCode: string
{
    /** The actor does not exist, or is pending, deactivated or deprovisioned. */
    case ActorNotActive = 'actor_not_active';

    /** The actor is a service actor, which never logs in. */
    case ClassNotAllowed = 'login_class_not_allowed';

    /** The policy of the actor's class does not list the connection. */
    case ConnectionNotAllowed = 'login_connection_not_allowed';

    /** The policy does not allow the method, or the method does not belong to the connection. */
    case MethodNotAllowed = 'login_method_not_allowed';

    /** Local login is switched off for the actor's class. */
    case LocalDisabled = 'login_local_disabled';

    /** A local login of an actor linked to an authoritative connection (invariant 38). */
    case AuthoritativeLink = 'login_authoritative_link';

    /** The login did not give the factors the policy requires. */
    case FactorsUnavailable = 'login_factors_unavailable';
}

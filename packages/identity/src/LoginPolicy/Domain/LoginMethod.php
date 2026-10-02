<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A way to log in (PRD 5.16, "Loginpolitik"). Every login path asks the login policy with its
 * method, and the session stores it, so a session from a method the policy no longer allows is
 * refused at its next request.
 *
 * Every method but Federated goes through the local connection, the local accounts of the identity
 * module; Federated is a login through any other connection, such as an OpenID Connect provider.
 */
#[Internal]
enum LoginMethod: string
{
    case Password = 'password';

    case Passkey = 'passkey';

    case MagicLink = 'magic_link';

    case Social = 'social';

    /** Accepting an invitation, which sets the first credential. */
    case Invitation = 'invitation';

    /** Resetting a password, which ends in a login. */
    case PasswordReset = 'password_reset';

    case Federated = 'federated';

    /**
     * Whether the method goes through the local connection.
     */
    public function isLocal(): bool
    {
        return $this !== self::Federated;
    }
}

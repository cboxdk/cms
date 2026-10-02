<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;

/**
 * Why the panel sent a browser to its login page (PRD 5.16), the query parameter PARAMETER of the
 * login page's address, which the page says in the person's language. It names the kind of reason
 * only, never the actor or the session.
 */
#[Internal]
enum SignInReason: string
{
    public const string PARAMETER = 'reason';

    /** The request carried no session. */
    case Required = 'required';

    /** The session passed its inactivity timeout or its absolute lifetime. */
    case Expired = 'expired';

    /** The session is no longer there, such as after a logout in another tab, or was never one. */
    case Ended = 'ended';

    /** The person's access changed: the actor is not active, its credentials were revoked, or the login policy no longer allows how it logged in. */
    case Revoked = 'revoked';

    /** The person logged out. */
    case SignedOut = 'signed_out';

    /**
     * The reason for a session the CredentialVerifier refused with $code.
     */
    public static function refused(CredentialErrorCode $code): self
    {
        return match ($code) {
            CredentialErrorCode::Expired => self::Expired,
            CredentialErrorCode::ActorNotActive, CredentialErrorCode::Revoked, CredentialErrorCode::NotAllowed => self::Revoked,
            CredentialErrorCode::Malformed, CredentialErrorCode::Unknown => self::Ended,
        };
    }
}

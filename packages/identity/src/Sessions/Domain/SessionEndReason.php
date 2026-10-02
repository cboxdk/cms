<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;

/**
 * Why a session ended (PRD 5.16), the attribute `cms.reason` of the counter `cms.session.ended`.
 */
#[Internal]
enum SessionEndReason: string
{
    /** The person logged out. */
    case Logout = 'logout';

    /** It went past its inactivity timeout or its absolute lifetime, found at a request. */
    case Expired = 'expired';

    /** Its actor was no longer active, or its generation was below the actor's, at a request. */
    case Revoked = 'revoked';

    /** The login policy no longer allowed how it was obtained, at a request. */
    case Policy = 'policy';

    /** Every session of its actor was ended, such as at a deactivation. */
    case Actor = 'actor';

    /** The identity provider ended the IdP session it came from, such as by a back-channel logout. */
    case IdpSession = 'idp_session';

    /**
     * The reason a session ends for when its verification refused it, or null when a refusal
     * leaves nothing to end.
     */
    public static function refused(CredentialErrorCode $code): ?self
    {
        return match ($code) {
            CredentialErrorCode::Expired => self::Expired,
            CredentialErrorCode::ActorNotActive, CredentialErrorCode::Revoked => self::Revoked,
            CredentialErrorCode::NotAllowed => self::Policy,
            CredentialErrorCode::Malformed, CredentialErrorCode::Unknown => null,
        };
    }
}

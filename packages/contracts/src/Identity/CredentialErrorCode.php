<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why CredentialVerifier::verify() refused a credential (PRD 5.16). Each is a code of the error
 * catalog, Cbox\Cms\Contracts\Errors\ErrorCode. A verifier checks in the order of the cases and
 * gives the first that applies.
 */
#[Experimental]
enum CredentialErrorCode: string
{
    /** Not in the form of a credential, or its checksum does not match: refused without a lookup. */
    case Malformed = 'credential_malformed';

    /** In the form, but no credential has it. */
    case Unknown = 'credential_unknown';

    /** Its expiry has passed. */
    case Expired = 'credential_expired';

    /** Its actor, or an actor in its on-behalf-of chain, is not active. */
    case ActorNotActive = 'actor_not_active';

    /** Its generation is lower than its actor's: it was revoked with everything else the actor held. */
    case Revoked = 'credential_revoked';

    /**
     * A session the login policy no longer allows: its actor class, its connection or its login
     * method is no longer allowed, or local login was switched off (PRD 5.16, "Loginpolitik").
     */
    case NotAllowed = 'credential_not_allowed';
}

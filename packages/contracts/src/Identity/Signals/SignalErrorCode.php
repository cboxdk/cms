<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a back-channel logout or a security event was refused (PRD 5.16). Each is a code of the error
 * catalog, Cbox\Cms\Contracts\Errors\ErrorCode, answered with 400 Bad Request.
 */
#[Experimental]
enum SignalErrorCode: string
{
    /** The token is from another issuer than the one the connection is pinned to. */
    case IssuerMismatch = 'signal_issuer_mismatch';

    /** The token's audience does not list the audience the connection is pinned to. */
    case AudienceMismatch = 'signal_audience_mismatch';

    /** The token was issued more than the allowed clock skew after the receiver's time. */
    case IssuedInFuture = 'signal_issued_in_future';

    /** The logout token's expiry has passed, beyond the allowed clock skew. */
    case Expired = 'signal_expired';

    /** The logout token's events do not hold the back-channel logout event. */
    case LogoutEventMissing = 'signal_logout_event_missing';

    /** The logout token carries a nonce, which a logout token never does. */
    case NoncePresent = 'signal_nonce_present';

    /** The logout token names neither a subject nor an IdP session. */
    case SubjectMissing = 'signal_subject_missing';

    /** The security event names its subject in another format than iss_sub, or with another issuer. */
    case SubjectUnsupported = 'signal_subject_unsupported';

    /** The security event is of a type the core does not act on. */
    case EventUnsupported = 'signal_event_unsupported';

    /** The logout token's jti was received before from the same issuer. */
    case Replayed = 'signal_replayed';
}

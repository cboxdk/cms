<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Turns a transport credential into a Principal (PRD 5.16, 6.2): the first phase of every command
 * and read.
 *
 * Without a credential the principal is the AnonymousPrincipal, for public reads and public writes
 * (invariant 25). A credential that is given is verified, and never falls back to anonymous when it
 * is refused. It is refused, with the first reason of CredentialErrorCode that applies, when:
 *
 * 1. it is not in its form, or its checksum does not match (credential_malformed), which is found
 *    without a lookup;
 * 2. no credential has it (credential_unknown);
 * 3. its expiry is not after the Clock's time (credential_expired);
 * 4. its actor, or any actor in its on-behalf-of chain, is not active (actor_not_active);
 * 5. its generation is lower than its actor's (credential_revoked);
 * 6. for a session, the login policy no longer allows how it was obtained (credential_not_allowed).
 *
 * A bearer token is a ServiceCredentialToken, and the session form a SessionToken; each form is read
 * only as itself. A session verifies to an ActorPrincipal of issuer kind Human, decided through
 * IssuedSession::principal(). A verifier that holds no sessions refuses a well-formed session id as
 * credential_unknown, as it would any credential it does not have.
 *
 * A verifier reads the current state from the primary, so a deactivation or a revocation that
 * committed is seen by the next verification.
 */
#[Experimental]
interface CredentialVerifier
{
    /**
     * @throws CredentialRejected
     */
    public function verify(?TransportCredential $credential): Principal;
}

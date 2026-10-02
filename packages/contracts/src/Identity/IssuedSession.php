<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * A session as its store keeps it, for the verification of its principal (PRD 5.16): the actor who
 * logged in and the actor's credential generation when the session was issued. A session acts on
 * behalf of no one; its issuer kind is Human, and its ceiling IssuerKind::Human's, which the grants
 * of the actor's roles narrow.
 *
 * principal() decides, from the current state of the actor, whether the session verifies, in the
 * order CredentialVerifier gives: the actor is active, then the generation is not below the
 * actor's. Its expiry, by inactivity and absolute lifetime, and the login policy it was issued
 * under are the store's to judge, before it asks principal(). Every verifier that holds sessions
 * decides through it, so the fake and a real store cannot differ in the rules.
 */
#[Experimental]
final readonly class IssuedSession
{
    public function __construct(
        public ActorId $actor,
        public CredentialGeneration $generation,
    ) {}

    /**
     * The principal of the session, given the current state of its actor.
     *
     * @throws CredentialRejected when the actor is not active, or the session was revoked
     * @throws InvalidIdentity when the actor given is not the session's
     */
    public function principal(Actor $actor): ActorPrincipal
    {
        if (! $actor->id->equals($this->actor)) {
            throw InvalidIdentity::mismatch('actor');
        }

        if (! $actor->isActive()) {
            throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
        }

        if ($this->generation->isBelow($actor->credentialGeneration)) {
            throw CredentialRejected::because(CredentialErrorCode::Revoked);
        }

        return new ActorPrincipal($this->actor, [], IssuerKind::Human, IssuerKind::Human->maximumCeiling());
    }
}

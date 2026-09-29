<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * A credential as its store keeps it (PRD 5.16): the actor it belongs to, the actors it acts on
 * behalf of, its issuer kind, its classification ceiling, the actor's credential generation when it
 * was issued, and its expiry. A credential always has an expiry.
 *
 * principal() decides, from the current state of those actors, whether the credential verifies,
 * in the order CredentialVerifier gives. Every verifier decides through it, so the fake and a real
 * store cannot differ in the rules.
 */
#[Experimental]
final readonly class IssuedCredential
{
    /**
     * @param  list<ActorId>  $onBehalfOf
     *
     * @throws InvalidIdentity when the chain holds the actor or an actor twice, or the ceiling is
     *                         above what the issuer kind permits
     */
    public function __construct(
        public ActorId $actor,
        public array $onBehalfOf,
        public IssuerKind $issuerKind,
        public ClassificationAccess $ceiling,
        public CredentialGeneration $generation,
        public DateTimeImmutable $expiresAt,
    ) {
        OnBehalfOf::check($actor, $onBehalfOf);

        if (! $issuerKind->permits($ceiling)) {
            throw InvalidIdentity::ceiling($issuerKind, $ceiling);
        }
    }

    /**
     * The principal of the credential at $now, given the current state of its actor and of the
     * actors of its chain, in the chain's order.
     *
     * @param  list<Actor>  $chain
     *
     * @throws CredentialRejected when it expired, an actor is not active or it was revoked
     * @throws InvalidIdentity when the actors given are not the credential's
     */
    public function principal(Actor $actor, array $chain, DateTimeImmutable $now): ActorPrincipal
    {
        if (! $actor->id->equals($this->actor)) {
            throw InvalidIdentity::mismatch('actor');
        }

        if (count($chain) !== count($this->onBehalfOf)) {
            throw InvalidIdentity::mismatch('on-behalf-of chain');
        }

        foreach ($chain as $index => $link) {
            if (! $link->id->equals($this->onBehalfOf[$index])) {
                throw InvalidIdentity::mismatch('on-behalf-of chain');
            }
        }

        if ($now >= $this->expiresAt) {
            throw CredentialRejected::because(CredentialErrorCode::Expired);
        }

        if (! $actor->isActive()) {
            throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
        }

        foreach ($chain as $link) {
            if (! $link->isActive()) {
                throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
            }
        }

        if ($this->generation->isBelow($actor->credentialGeneration)) {
            throw CredentialRejected::because(CredentialErrorCode::Revoked);
        }

        return new ActorPrincipal($this->actor, $this->onBehalfOf, $this->issuerKind, $this->ceiling);
    }
}

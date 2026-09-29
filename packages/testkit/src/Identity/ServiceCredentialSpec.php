<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * A service credential to issue with IdentitySeeder::issue() (PRD 5.16): the service actor it
 * belongs to, its issuer kind, its classification ceiling, its expiry and the actors it acts on
 * behalf of, in order. A credential for an agent is refused here with a ceiling above
 * confidential (PRD 2.31).
 */
#[Experimental]
final readonly class ServiceCredentialSpec
{
    /**
     * @param  list<ActorId>  $onBehalfOf
     *
     * @throws InvalidIdentity when the ceiling is above what the issuer kind permits
     */
    public function __construct(
        public ActorId $actor,
        public IssuerKind $issuerKind,
        public ClassificationAccess $ceiling,
        public DateTimeImmutable $expiresAt,
        public array $onBehalfOf = [],
    ) {
        if (! $issuerKind->permits($ceiling)) {
            throw InvalidIdentity::ceiling($issuerKind, $ceiling);
        }
    }

    /**
     * The credential a seeder stores for the spec, at $now, given the current state of the actor
     * and of the chain's actors in its order.
     *
     * @param  list<Actor>  $chain
     *
     * @throws InvalidIdentity when an actor is not active, the actor is not of the class service,
     *                         or the expiry is not after $now
     */
    public function issue(Actor $actor, array $chain, DateTimeImmutable $now): IssuedCredential
    {
        if ($actor->class !== ActorClass::Service) {
            throw InvalidIdentity::notServiceActor($actor->id, $actor->class);
        }

        foreach ([$actor, ...$chain] as $member) {
            if (! $member->isActive()) {
                throw InvalidIdentity::inactiveActor($member->id, $member->state);
            }
        }

        if ($this->expiresAt <= $now) {
            throw InvalidIdentity::expiry($this->expiresAt, $now);
        }

        return new IssuedCredential(
            $this->actor,
            $this->onBehalfOf,
            $this->issuerKind,
            $this->ceiling,
            $actor->credentialGeneration,
            $this->expiresAt,
        );
    }
}

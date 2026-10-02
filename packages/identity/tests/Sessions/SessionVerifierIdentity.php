<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Sessions;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Identity\SessionIdentityHarness;

/**
 * The harness the shared identity suites run the session verifier through: the actors and the
 * service credentials are FakeIdentity's, the verifier is SessionCredentialVerifier over it with
 * FakeSessionStore, and a session is started as a login path starts one, through the login policy
 * and IssueSession (SessionWorld).
 */
final readonly class SessionVerifierIdentity implements SessionIdentityHarness
{
    public function __construct(private SessionWorld $world) {}

    public function directory(): ActorDirectory
    {
        return $this->world->identity;
    }

    public function verifier(): CredentialVerifier
    {
        return $this->world->verifier();
    }

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        return $this->world->identity->addActor($class, $state);
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        return $this->world->identity->issue($spec);
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->world->identity->changeState($id, $state);
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->world->identity->revokeCredentials($id);
    }

    public function startSession(ActorId $actor): TransportCredential
    {
        $person = $this->world->identity->find($actor) ?? throw InvalidIdentity::unknownActor($actor);

        if ($person->class === ActorClass::Service) {
            throw InvalidIdentity::serviceActorSession($actor);
        }

        if (! $person->isActive()) {
            throw InvalidIdentity::inactiveActor($actor, $person->state);
        }

        return SessionWorld::credential($this->world->login($person));
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialForm;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuedSession;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;

/**
 * The in-memory fake of ActorDirectory and CredentialVerifier, and its own seeder (GUARDRAILS 2.3).
 *
 * It keeps actors, service credentials and sessions in memory, makes actor ids with the
 * IdGenerator it is given and reads the time from its Clock, a FakeClock by default, so a test can
 * move the clock past a credential's expiry. It keeps a credential and a session only as the hash
 * of its token, as a real store does, and decides a verification through
 * IssuedCredential::principal() or IssuedSession::principal(), as every verifier does. A session
 * here has no expiry and no login policy: those are the session store's, which the identity module
 * tests with its own fakes. lookups() counts the verifications that looked a credential or a
 * session up, so a test can see that a malformed one was refused without one.
 */
#[Experimental]
final class FakeIdentity implements ActorDirectory, CredentialVerifier, SessionIdentityHarness
{
    /** @var array<string, Actor> by actor id */
    private array $actors = [];

    /** @var array<string, IssuedCredential> by the hash of the token */
    private array $credentials = [];

    /** @var array<string, IssuedSession> by the hash of the session id */
    private array $sessions = [];

    private int $lookups = 0;

    private readonly IdGenerator $ids;

    public function __construct(
        private readonly Clock $clock = new FakeClock,
        ?IdGenerator $ids = null,
    ) {
        $this->ids = $ids ?? new FakeIdGenerator(clock: $clock);
    }

    public function directory(): ActorDirectory
    {
        return $this;
    }

    public function verifier(): CredentialVerifier
    {
        return $this;
    }

    public function find(ActorId $id): ?Actor
    {
        return $this->actors[$id->toString()] ?? null;
    }

    public function verify(?TransportCredential $credential): Principal
    {
        if (! $credential instanceof TransportCredential) {
            return new AnonymousPrincipal;
        }

        if ($credential->form === CredentialForm::Session) {
            $session = SessionToken::parse($credential);
            $this->lookups++;
            $issuedSession = $this->sessions[$session->hash()] ?? throw CredentialRejected::because(CredentialErrorCode::Unknown);

            return $issuedSession->principal($this->actor($issuedSession->actor));
        }

        $token = ServiceCredentialToken::parse($credential);
        $this->lookups++;
        $issued = $this->credentials[$token->hash()] ?? throw CredentialRejected::because(CredentialErrorCode::Unknown);

        return $issued->principal($this->actor($issued->actor), $this->chain($issued->onBehalfOf), $this->clock->now());
    }

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        $actor = new Actor(new ActorId($this->ids->next()), $class, $state, Actor::FIRST_VERSION, CredentialGeneration::first());

        return $this->actors[$actor->id->toString()] = $actor;
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        $issued = $spec->issue($this->actor($spec->actor), $this->chain($spec->onBehalfOf), $this->clock->now());
        $token = ServiceCredentialToken::fromSecret(random_bytes(ServiceCredentialToken::SECRET_BYTES));
        $this->credentials[$token->hash()] = $issued;

        return $token->credential();
    }

    public function startSession(ActorId $actor): TransportCredential
    {
        $person = $this->actor($actor);

        if ($person->class === ActorClass::Service) {
            throw InvalidIdentity::serviceActorSession($actor);
        }

        if (! $person->isActive()) {
            throw InvalidIdentity::inactiveActor($actor, $person->state);
        }

        $token = SessionToken::fromSecret(random_bytes(SessionToken::SECRET_BYTES));
        $this->sessions[$token->hash()] = new IssuedSession($actor, $person->credentialGeneration);

        return $token->credential();
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->actors[$id->toString()] = ActorChanges::state($this->actor($id), $state);
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->actors[$id->toString()] = ActorChanges::revoke($this->actor($id));
    }

    /**
     * How many verifications looked a credential or a session up.
     */
    public function lookups(): int
    {
        return $this->lookups;
    }

    /**
     * @throws InvalidIdentity
     */
    private function actor(ActorId $id): Actor
    {
        return $this->find($id) ?? throw InvalidIdentity::unknownActor($id);
    }

    /**
     * @param  list<ActorId>  $ids
     * @return list<Actor>
     */
    private function chain(array $ids): array
    {
        return array_map($this->actor(...), $ids);
    }
}

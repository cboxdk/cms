<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Testkit\Clock\FakeClock;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for a CredentialVerifier that holds sessions (PRD 5.16), beside
 * CredentialVerifierContract, which every verifier runs. The fake runs it, and so does the verifier
 * the identity module decorates with its session store.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory, next to
 * CredentialVerifierContract, and return a harness for a new, empty verifier from
 * sessionIdentity():
 *
 *     final class FakeCredentialVerifierContractTest extends TestCase
 *     {
 *         use CredentialVerifierContract;
 *         use SessionCredentialContract;
 *
 *         protected function identity(Clock $clock): IdentityHarness
 *         {
 *             return new FakeIdentity($clock);
 *         }
 *
 *         protected function sessionIdentity(Clock $clock): SessionIdentityHarness
 *         {
 *             return new FakeIdentity($clock);
 *         }
 *     }
 *
 * The cases cover the principal of a session, an actor of issuer kind human on behalf of no one
 * with the ceiling IssuerKind::Human permits, for staff and end users; that no session is started
 * for a service actor; that a session id is read only in the session form; and the refusals of a
 * session in their order: an actor that is not active, and a generation below the actor's after a
 * revocation or a reactivation. A session's expiry and the login policy it was issued under are
 * the store's, which the identity module tests with its own suites.
 */
#[Experimental]
trait SessionCredentialContract
{
    /**
     * A harness for a new, empty verifier that holds sessions, whose parts read the time from
     * $clock.
     */
    abstract protected function sessionIdentity(Clock $clock): SessionIdentityHarness;

    #[Test]
    public function a_session_gives_its_actor_as_a_human_on_behalf_of_no_one(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);

        foreach ([ActorClass::Staff, ActorClass::EndUser] as $class) {
            $actor = $identity->addActor($class);

            $principal = $this->sessionVerified($identity->verifier(), $identity->startSession($actor->id));

            Assert::assertTrue($principal->actor->equals($actor->id), 'The principal is another actor.');
            Assert::assertSame([], $principal->onBehalfOf);
            Assert::assertSame(IssuerKind::Human, $principal->issuerKind);
            Assert::assertSame(ClassificationAccess::Sensitive, $principal->ceiling);
        }
    }

    #[Test]
    public function it_gives_each_session_its_own_id(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Staff);

        $first = $identity->startSession($actor->id);
        $second = $identity->startSession($actor->id);

        Assert::assertNotSame($first->reveal(), $second->reveal());
        $this->sessionVerified($identity->verifier(), $first);
        $this->sessionVerified($identity->verifier(), $second);
    }

    #[Test]
    public function it_starts_no_session_for_a_service_actor_or_an_actor_that_is_not_active(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $refused = 0;

        foreach ([$identity->addActor(ActorClass::Service), $identity->addActor(ActorClass::Staff, ActorState::Pending)] as $actor) {
            try {
                $identity->startSession($actor->id);
            } catch (InvalidIdentity) {
                // Refused, as the login policy would refuse the login.
                $refused++;
            }
        }

        Assert::assertSame(2, $refused, 'A session was started for an actor that cannot log in.');
    }

    #[Test]
    public function it_reads_a_session_id_only_in_the_session_form(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $session = $identity->startSession($identity->addActor(ActorClass::Staff)->id);

        $this->assertSessionRefused(CredentialErrorCode::Malformed, $identity->verifier(), new TransportCredential($session->reveal()));
        $this->sessionVerified($identity->verifier(), $session);
    }

    #[Test]
    public function it_refuses_a_session_once_its_actor_is_not_active(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);

        foreach ([ActorState::Deactivated, ActorState::Deprovisioned, ActorState::Pending] as $state) {
            $actor = $identity->addActor(ActorClass::Staff);
            $session = $identity->startSession($actor->id);
            $this->sessionVerified($identity->verifier(), $session);

            $identity->changeState($actor->id, $state);

            $this->assertSessionRefused(CredentialErrorCode::ActorNotActive, $identity->verifier(), $session);
        }
    }

    #[Test]
    public function it_refuses_a_session_whose_generation_is_below_the_actors(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Staff);
        $session = $identity->startSession($actor->id);

        $identity->revokeCredentials($actor->id);

        $this->assertSessionRefused(CredentialErrorCode::Revoked, $identity->verifier(), $session);
        $this->sessionVerified($identity->verifier(), $identity->startSession($actor->id));
    }

    #[Test]
    public function a_reactivated_actor_does_not_get_its_earlier_sessions_back(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Staff);
        $session = $identity->startSession($actor->id);

        $identity->changeState($actor->id, ActorState::Deactivated);
        $identity->changeState($actor->id, ActorState::Active);

        $this->assertSessionRefused(CredentialErrorCode::Revoked, $identity->verifier(), $session);
    }

    #[Test]
    public function it_checks_the_actor_of_a_session_before_its_generation(): void
    {
        $identity = $this->sessionIdentity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Staff);
        $session = $identity->startSession($actor->id);

        $identity->revokeCredentials($actor->id);
        $identity->changeState($actor->id, ActorState::Deactivated);

        $this->assertSessionRefused(CredentialErrorCode::ActorNotActive, $identity->verifier(), $session);
    }

    private function sessionVerified(CredentialVerifier $verifier, TransportCredential $session): ActorPrincipal
    {
        $principal = $verifier->verify($session);
        Assert::assertInstanceOf(ActorPrincipal::class, $principal, 'A valid session did not give its actor.');

        return $principal;
    }

    private function assertSessionRefused(CredentialErrorCode $reason, CredentialVerifier $verifier, TransportCredential $session): void
    {
        try {
            $principal = $verifier->verify($session);
        } catch (CredentialRejected $rejected) {
            Assert::assertSame($reason, $rejected->reason, sprintf('The session was refused as %s, not %s.', $rejected->reason->value, $reason->value));

            return;
        }

        Assert::fail(sprintf('The verifier gave %s instead of refusing the session as %s.', $principal::class, $reason->value));
    }
}

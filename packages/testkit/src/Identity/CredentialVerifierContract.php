<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for CredentialVerifier (GUARDRAILS 2.3 and 9). The fake and every real
 * verifier run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new, empty verifier from identity(). Its verifier reads the time from the given
 * clock, which the cases move past a credential's expiry:
 *
 *     final class FakeCredentialVerifierContractTest extends TestCase
 *     {
 *         use CredentialVerifierContract;
 *
 *         protected function identity(Clock $clock): IdentityHarness
 *         {
 *             return new FakeIdentity($clock);
 *         }
 *     }
 *
 * The cases cover the anonymous principal, the principal of a service and an agent credential with
 * its chain, and every refusal of CredentialVerifier in its order: a malformed token or a wrong
 * checksum, an unknown token, an expired one, an actor or an actor of the chain that is not active,
 * and a generation below the actor's after a revocation or a reactivation.
 */
#[Experimental]
trait CredentialVerifierContract
{
    /**
     * A harness for a new, empty verifier whose parts read the time from $clock.
     */
    abstract protected function identity(Clock $clock): IdentityHarness;

    #[Test]
    public function no_credential_gives_the_anonymous_principal_with_public_access(): void
    {
        $principal = $this->identity(new FakeClock)->verifier()->verify(null);

        Assert::assertInstanceOf(AnonymousPrincipal::class, $principal);
        Assert::assertSame(ClassificationAccess::Public, $principal->classificationCeiling());
    }

    #[Test]
    public function a_service_credential_gives_its_actor_kind_and_ceiling(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Personal, $this->later($clock, 'P30D')));

        $principal = $this->verified($identity->verifier(), $credential);

        Assert::assertTrue($principal->actor->equals($actor->id), 'The principal is another actor.');
        Assert::assertSame([], $principal->onBehalfOf);
        Assert::assertSame(IssuerKind::Service, $principal->issuerKind);
        Assert::assertSame(ClassificationAccess::Personal, $principal->classificationCeiling());
    }

    #[Test]
    public function an_agent_credential_on_behalf_of_a_person_gives_the_kind_agent_and_the_chain(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $agent = $identity->addActor(ActorClass::Service);
        $person = $identity->addActor(ActorClass::Staff);
        $credential = $identity->issue(new ServiceCredentialSpec($agent->id, IssuerKind::Agent, ClassificationAccess::Confidential, $this->later($clock, 'P1D'), [$person->id]));

        $principal = $this->verified($identity->verifier(), $credential);

        Assert::assertTrue($principal->actor->equals($agent->id), 'The principal is another actor.');
        Assert::assertCount(1, $principal->onBehalfOf);
        Assert::assertTrue($principal->onBehalfOf[0]->equals($person->id), 'The chain names another actor.');
        Assert::assertSame(IssuerKind::Agent, $principal->issuerKind);
        Assert::assertSame(ClassificationAccess::Confidential, $principal->ceiling);
    }

    #[Test]
    public function it_keeps_the_order_of_a_longer_chain(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $agent = $identity->addActor(ActorClass::Service);
        $chain = [$identity->addActor(ActorClass::Service)->id, $identity->addActor(ActorClass::Staff)->id];
        $credential = $identity->issue(new ServiceCredentialSpec($agent->id, IssuerKind::Agent, ClassificationAccess::Public, $this->later($clock, 'P1D'), $chain));

        $principal = $this->verified($identity->verifier(), $credential);

        Assert::assertSame(
            array_map(static fn (ActorId $id): string => $id->toString(), $chain),
            array_map(static fn (ActorId $id): string => $id->toString(), $principal->onBehalfOf),
        );
    }

    #[Test]
    public function it_refuses_a_token_that_is_not_in_the_form_or_has_a_wrong_checksum(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $valid = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'P1D')))->reveal();
        $last = $valid[strlen($valid) - 1];
        $wrongChecksum = substr($valid, 0, -1).($last === '0' ? '1' : '0');

        foreach (['', 'Bearer', 'cms_sc_', strtoupper($valid), $valid.'0', substr($valid, 0, -1), ' '.$valid, $wrongChecksum] as $token) {
            $this->assertRefused(CredentialErrorCode::Malformed, $identity->verifier(), new TransportCredential($token));
        }

        $this->verified($identity->verifier(), new TransportCredential($valid));
    }

    #[Test]
    public function it_refuses_a_well_formed_token_that_no_credential_has(): void
    {
        $identity = $this->identity(new FakeClock);
        $identity->addActor(ActorClass::Service);
        $unknown = ServiceCredentialToken::fromSecret(str_repeat("\x2a", ServiceCredentialToken::SECRET_BYTES))->credential();

        $this->assertRefused(CredentialErrorCode::Unknown, $identity->verifier(), $unknown);
    }

    #[Test]
    public function it_refuses_a_credential_once_its_expiry_is_reached(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $expiresAt = $this->later($clock, 'PT1H');
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $expiresAt));

        $clock->set($expiresAt->modify('-1 millisecond'));
        $this->verified($identity->verifier(), $credential);

        $clock->set($expiresAt);
        $this->assertRefused(CredentialErrorCode::Expired, $identity->verifier(), $credential);
    }

    #[Test]
    public function it_refuses_a_credential_whose_actor_is_deactivated_or_deprovisioned(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);

        foreach ([ActorState::Deactivated, ActorState::Deprovisioned, ActorState::Pending] as $state) {
            $actor = $identity->addActor(ActorClass::Service);
            $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'P1D')));
            $this->verified($identity->verifier(), $credential);

            $identity->changeState($actor->id, $state);

            $this->assertRefused(CredentialErrorCode::ActorNotActive, $identity->verifier(), $credential);
        }
    }

    #[Test]
    public function it_refuses_a_credential_when_an_actor_of_its_chain_is_not_active(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $agent = $identity->addActor(ActorClass::Service);
        $person = $identity->addActor(ActorClass::Staff);
        $credential = $identity->issue(new ServiceCredentialSpec($agent->id, IssuerKind::Agent, ClassificationAccess::Internal, $this->later($clock, 'P1D'), [$person->id]));

        $identity->changeState($person->id, ActorState::Deactivated);

        $this->assertRefused(CredentialErrorCode::ActorNotActive, $identity->verifier(), $credential);
    }

    #[Test]
    public function it_refuses_a_credential_whose_generation_is_below_the_actors(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'P1D')));

        $identity->revokeCredentials($actor->id);

        $this->assertRefused(CredentialErrorCode::Revoked, $identity->verifier(), $credential);

        $fresh = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'P1D')));
        $this->verified($identity->verifier(), $fresh);
    }

    #[Test]
    public function a_reactivated_actor_does_not_get_its_earlier_credentials_back(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'P1D')));

        $identity->changeState($actor->id, ActorState::Deactivated);
        $identity->changeState($actor->id, ActorState::Active);

        $this->assertRefused(CredentialErrorCode::Revoked, $identity->verifier(), $credential);
    }

    #[Test]
    public function it_checks_expiry_before_the_actor_and_the_actor_before_the_generation(): void
    {
        $clock = new FakeClock;
        $identity = $this->identity($clock);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $this->later($clock, 'PT1H')));

        $identity->changeState($actor->id, ActorState::Deactivated);
        $this->assertRefused(CredentialErrorCode::ActorNotActive, $identity->verifier(), $credential);

        $clock->advance(new DateInterval('PT2H'));
        $this->assertRefused(CredentialErrorCode::Expired, $identity->verifier(), $credential);
    }

    private function later(Clock $clock, string $interval): DateTimeImmutable
    {
        return $clock->now()->add(new DateInterval($interval));
    }

    private function verified(CredentialVerifier $verifier, TransportCredential $credential): ActorPrincipal
    {
        $principal = $verifier->verify($credential);
        Assert::assertInstanceOf(ActorPrincipal::class, $principal, 'A valid credential did not give its actor.');

        return $principal;
    }

    private function assertRefused(CredentialErrorCode $reason, CredentialVerifier $verifier, TransportCredential $credential): void
    {
        try {
            $principal = $verifier->verify($credential);
        } catch (CredentialRejected $rejected) {
            Assert::assertSame($reason, $rejected->reason, sprintf('The credential was refused as %s, not %s.', $rejected->reason->value, $reason->value));

            return;
        }

        Assert::fail(sprintf('The verifier gave %s instead of refusing the credential as %s.', $principal::class, $reason->value));
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ServiceCredentialIssuer does, the fake and the Postgres one alike: it issues a token
 * of the service credential form that the verifier verifies to the actor with the issuer kind, the
 * ceiling and the generation of the credential, each issue a token of its own; the token expires
 * when the credential says; and without the owner connection it refuses with
 * maintenance_process_required before it issues anything.
 */
trait ServiceCredentialIssuerBehaviour
{
    /** The time the credentials are issued at. */
    public const string ISSUED_AT = '2026-03-10T12:00:00Z';

    /** The issuer under test, with the owner connection. */
    abstract protected function credentialIssuer(): ServiceCredentialIssuer;

    /** The issuer under test in a process without the owner connection. */
    abstract protected function issuerWithoutOwner(): ServiceCredentialIssuer;

    /** The verifier that reads what the issuer wrote, at issuerClock(). */
    abstract protected function credentialVerifier(): CredentialVerifier;

    /** An active service actor of the harness. */
    abstract protected function serviceActor(): Actor;

    /** The clock the issuer and the verifier share. */
    abstract protected function issuerClock(): FakeClock;

    #[Test]
    public function it_issues_a_token_the_verifier_verifies_to_the_actor_with_the_ceiling_and_the_issuer_kind(): void
    {
        $actor = $this->serviceActor();

        $token = $this->credentialIssuer()->issue($this->credential($actor, ClassificationAccess::Confidential));
        $principal = $this->credentialVerifier()->verify($token);

        Assert::assertSame(ServiceCredentialToken::PREFIX, substr($token->reveal(), 0, strlen(ServiceCredentialToken::PREFIX)));
        Assert::assertInstanceOf(ActorPrincipal::class, $principal);
        Assert::assertTrue($principal->actor->equals($actor->id));
        Assert::assertSame(IssuerKind::Service, $principal->issuerKind);
        Assert::assertSame(ClassificationAccess::Confidential, $principal->ceiling);
        Assert::assertSame([], $principal->onBehalfOf);
    }

    #[Test]
    public function each_issue_gives_a_token_of_its_own_and_every_one_verifies(): void
    {
        $actor = $this->serviceActor();
        $issuer = $this->credentialIssuer();

        $first = $issuer->issue($this->credential($actor, ClassificationAccess::Internal));
        $second = $issuer->issue($this->credential($actor, ClassificationAccess::Internal));

        Assert::assertNotSame($first->reveal(), $second->reveal());

        foreach ([$first, $second] as $token) {
            $principal = $this->credentialVerifier()->verify($token);
            Assert::assertInstanceOf(ActorPrincipal::class, $principal);
            Assert::assertTrue($principal->actor->equals($actor->id));
        }
    }

    #[Test]
    public function the_token_is_refused_as_expired_once_the_clock_reaches_the_credential_s_expiry(): void
    {
        $token = $this->credentialIssuer()->issue($this->credential($this->serviceActor(), ClassificationAccess::Internal));
        $this->issuerClock()->advance(new DateInterval('P1D'));

        try {
            $this->credentialVerifier()->verify($token);
            Assert::fail('The expired token verified.');
        } catch (CredentialRejected $rejected) {
            Assert::assertSame(CredentialErrorCode::Expired, $rejected->reason);
        }
    }

    #[Test]
    public function it_refuses_without_the_owner_connection_and_issues_nothing(): void
    {
        try {
            $this->issuerWithoutOwner()->issue($this->credential($this->serviceActor(), ClassificationAccess::Internal));
            Assert::fail('The issuer issued without the owner connection.');
        } catch (CredentialIssueRefused $refused) {
            Assert::assertSame(ErrorCode::MaintenanceProcessRequired, $refused->error->code);
        }
    }

    protected function credential(Actor $actor, ClassificationAccess $ceiling): IssuedCredential
    {
        return new IssuedCredential($actor->id, [], IssuerKind::Service, $ceiling, $actor->credentialGeneration, $this->issuerClock()->now()->add(new DateInterval('P1D')));
    }
}

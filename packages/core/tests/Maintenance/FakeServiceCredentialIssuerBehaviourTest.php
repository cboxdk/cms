<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeServiceCredentialIssuer;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Override;

/**
 * ServiceCredentialIssuerBehaviour against the fake the maintenance action tests use, over a
 * FakeIdentity's actors.
 */
final class FakeServiceCredentialIssuerBehaviourTest extends TestCase
{
    use ServiceCredentialIssuerBehaviour;

    private ?FakeClock $clock = null;

    private ?FakeIdentity $identity = null;

    private ?FakeServiceCredentialIssuer $issuer = null;

    #[Override]
    protected function credentialIssuer(): ServiceCredentialIssuer
    {
        return $this->fake();
    }

    #[Override]
    protected function issuerWithoutOwner(): ServiceCredentialIssuer
    {
        return new FakeServiceCredentialIssuer($this->fakeIdentity(), $this->issuerClock(), ownerConnection: false);
    }

    #[Override]
    protected function credentialVerifier(): CredentialVerifier
    {
        return $this->fake();
    }

    #[Override]
    protected function serviceActor(): Actor
    {
        return $this->fakeIdentity()->addActor(ActorClass::Service);
    }

    #[Override]
    protected function issuerClock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable(self::ISSUED_AT));
    }

    private function fakeIdentity(): FakeIdentity
    {
        return $this->identity ??= new FakeIdentity($this->issuerClock());
    }

    private function fake(): FakeServiceCredentialIssuer
    {
        return $this->issuer ??= new FakeServiceCredentialIssuer($this->fakeIdentity(), $this->issuerClock());
    }
}

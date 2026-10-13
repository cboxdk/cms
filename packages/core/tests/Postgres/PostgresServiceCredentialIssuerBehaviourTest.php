<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresServiceCredentialIssuer;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Maintenance\ServiceCredentialIssuerBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * ServiceCredentialIssuerBehaviour against PostgresServiceCredentialIssuer on real Postgres: the
 * credential written as the owner role on the owner connection, and read back by the core's
 * credential verifier as the app role, at one FakeClock.
 */
final class PostgresServiceCredentialIssuerBehaviourTest extends TestCase
{
    use RealPostgres;
    use ServiceCredentialIssuerBehaviour;

    private ?FakeClock $clock = null;

    private ?PostgresIdentity $identity = null;

    #[Override]
    protected function credentialIssuer(): ServiceCredentialIssuer
    {
        return new PostgresServiceCredentialIssuer(app(ConnectionResolverInterface::class), $this->issuerClock(), new FakeIdGenerator(seed: 2601, clock: $this->issuerClock()), config()->string('cbox-cms.database.owner_connection'));
    }

    #[Override]
    protected function issuerWithoutOwner(): ServiceCredentialIssuer
    {
        return new PostgresServiceCredentialIssuer(app(ConnectionResolverInterface::class), $this->issuerClock(), new FakeIdGenerator(seed: 2602, clock: $this->issuerClock()), null);
    }

    #[Override]
    protected function credentialVerifier(): CredentialVerifier
    {
        return $this->postgresIdentity()->verifier();
    }

    #[Override]
    protected function serviceActor(): Actor
    {
        return $this->postgresIdentity()->addActor(ActorClass::Service);
    }

    #[Override]
    protected function issuerClock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable(self::ISSUED_AT));
    }

    private function postgresIdentity(): PostgresIdentity
    {
        return $this->identity ??= PostgresIdentity::at($this->issuerClock());
    }
}

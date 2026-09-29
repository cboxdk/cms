<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier;
use Cbox\Cms\Testkit\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Illuminate\Database\DatabaseManager;

/**
 * The shared identity suites' harness for the core's Postgres adapters: the testkit's
 * PostgresIdentitySeeder writes as the owner role, and the directory and the verifier read as the
 * app role on the default connection, at the harness's clock.
 */
final readonly class PostgresIdentity implements IdentityHarness
{
    private function __construct(
        private PostgresIdentitySeeder $seeder,
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public static function at(Clock $clock): self
    {
        $database = app(DatabaseManager::class);

        return new self(new PostgresIdentitySeeder($database, $clock, new FakeIdGenerator(clock: $clock)), $database, $clock);
    }

    public function directory(): PostgresActorDirectory
    {
        return new PostgresActorDirectory($this->database);
    }

    public function verifier(): PostgresCredentialVerifier
    {
        return new PostgresCredentialVerifier($this->database, $this->clock);
    }

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        return $this->seeder->addActor($class, $state);
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        return $this->seeder->issue($spec);
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->seeder->changeState($id, $state);
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->seeder->revokeCredentials($id);
    }
}

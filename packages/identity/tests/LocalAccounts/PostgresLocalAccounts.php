<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LocalAccounts;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Identity\LocalCredentialStoreHarness;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Override;

/**
 * The harness of LocalCredentialStoreContract for the Postgres store: the store on the identity
 * connection of phpunit.xml, `pgsql_identity`, at a FakeClock, with the actors the testkit's
 * PostgresIdentitySeeder writes as the owner role.
 */
final readonly class PostgresLocalAccounts implements LocalCredentialStoreHarness
{
    public const string CONNECTION = 'pgsql_identity';

    private PostgresIdentitySeeder $seeder;

    private PostgresLocalCredentialStore $store;

    public function __construct(private FakeClock $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00.123456Z')))
    {
        $database = app(DatabaseManager::class);
        $this->seeder = new PostgresIdentitySeeder($database, $clock, new FakeIdGenerator(clock: $clock));
        $this->store = new PostgresLocalCredentialStore($database, self::CONNECTION, $clock);
    }

    #[Override]
    public function store(): LocalCredentialStore
    {
        return $this->store;
    }

    #[Override]
    public function clock(): FakeClock
    {
        return $this->clock;
    }

    #[Override]
    public function actor(): ActorId
    {
        return $this->seeder->addActor(ActorClass::Staff, ActorState::Active)->id;
    }
}

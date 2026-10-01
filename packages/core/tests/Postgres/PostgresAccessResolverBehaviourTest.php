<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Tests\Access\AccessResolverBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * AccessResolverBehaviour against the container's AccessResolver, PostgresAccessResolver on the
 * default connection, as the app role on real Postgres, over the access world: BOB holds the role
 * desk (ceiling internal) on SPORT, SERVICE holds no grant, and DELEGATE holds the role agent
 * (ceiling sensitive) on ROOT with a credential issued on behalf of BOB and SERVICE.
 */
final class PostgresAccessResolverBehaviourTest extends TestCase
{
    use AccessResolverBehaviour;
    use RealPostgres;

    private const string DELEGATE = '0192a0c0-0000-7000-8000-0000000000c7';

    private const string CREDENTIAL = '0192a0c0-0000-7000-8000-0000000000c8';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        AccessWorld::seed();
        AccessWorld::delegate(self::DELEGATE, self::CREDENTIAL, [AccessWorld::BOB, AccessWorld::SERVICE]);
        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 41, clock: $clock));
        $fixtures->grant(ActorId::fromString(self::DELEGATE), $fixtures->role('agent', ClassificationAccess::Sensitive), NodeId::fromString(AccessWorld::ROOT));
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function accessResolver(): AccessResolver
    {
        return app(AccessResolver::class);
    }

    #[Override]
    protected function grantedActor(): ActorId
    {
        return ActorId::fromString(AccessWorld::BOB);
    }

    #[Override]
    protected function grantedRegions(): array
    {
        return [new AccessRegion(new NodePath(AccessWorld::path(AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT)))];
    }

    #[Override]
    protected function ungrantedActor(): ActorId
    {
        return ActorId::fromString(AccessWorld::SERVICE);
    }

    #[Override]
    protected function delegate(): ActorId
    {
        return ActorId::fromString(self::DELEGATE);
    }

    #[Override]
    protected function begin(): void
    {
        app(DatabaseManager::class)->connection()->beginTransaction();
    }

    #[Override]
    protected function end(): void
    {
        app(DatabaseManager::class)->connection()->rollBack();
    }
}

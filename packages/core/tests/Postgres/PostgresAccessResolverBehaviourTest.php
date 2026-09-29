<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Tests\Access\AccessResolverBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * AccessResolverBehaviour against the container's AccessResolver, PostgresAccessResolver on the
 * default connection, as the app role on real Postgres, over the access world: BOB holds the role
 * desk (ceiling internal) on SPORT, and SERVICE holds no grant.
 */
final class PostgresAccessResolverBehaviourTest extends TestCase
{
    use AccessResolverBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        AccessWorld::seed();
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

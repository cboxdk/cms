<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Fragments\Adapter\PostgresSubtreeNodes;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use Cbox\Cms\Core\Tests\Fragments\SubtreeNodesBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * SubtreeNodesBehaviour against PostgresSubtreeNodes on real Postgres, as the app role inside a
 * transaction under the actor context of a service actor whose one region is FIRST, so the rest of
 * the tree lies outside it and is read past the regions. The rows are written as the superuser.
 */
final class PostgresSubtreeNodesBehaviourTest extends TestCase
{
    use RealPostgres;
    use SubtreeNodesBehaviour;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = StorageTables::label(self::ROOT);
        $first = $root.'.'.StorageTables::label(self::FIRST);
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::FIRST, self::ROOT, $root));
        $superuser->table('nodes')->insert(StorageTables::node(self::SECOND, self::ROOT, $root));
        $superuser->table('nodes')->insert(StorageTables::node(self::THIRD, self::FIRST, $first, 'page'));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Service)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Internal),
            [new AccessRegion(new NodePath($first))],
            ClassificationAccess::Internal,
        ));
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::connection()->rollBack();
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function subtreeNodes(): SubtreeNodes
    {
        return new PostgresSubtreeNodes(app(ConnectionResolverInterface::class));
    }
}

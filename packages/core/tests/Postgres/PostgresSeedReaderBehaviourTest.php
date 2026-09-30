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
use Cbox\Cms\Core\Seeding\Adapter\PostgresSeedReader;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Cbox\Cms\Core\Tests\Seeding\SeedReaderBehaviour;
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
 * SeedReaderBehaviour against PostgresSeedReader on real Postgres, as the app role inside a
 * transaction under the actor context of a service actor whose region is ROOT above NODE. OTHER_NODE
 * is a root of its own, out of the region, so it reads as absent. The rows are written as the
 * superuser.
 */
final class PostgresSeedReaderBehaviourTest extends TestCase
{
    use RealPostgres;
    use SeedReaderBehaviour;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert([...StorageTables::node(self::NODE, self::ROOT, StorageTables::label(self::ROOT)), 'version' => 3]);
        $superuser->table('nodes')->insert(StorageTables::node(self::OTHER_NODE, kind: 'site'));

        foreach ([self::ENTRY, self::OTHER_ENTRY] as $entry) {
            $superuser->table('entries')->insert(['id' => $entry, 'type_id' => self::TYPE, 'home_node_id' => self::NODE, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT]);
        }

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Service)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::ROOT)))],
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
    protected function seedReader(): SeedReader
    {
        return new PostgresSeedReader(app(ConnectionResolverInterface::class));
    }
}

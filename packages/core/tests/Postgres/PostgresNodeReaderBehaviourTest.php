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
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeReader;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Cbox\Cms\Core\Tests\Structure\NodeReaderBehaviour;
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
 * NodeReaderBehaviour against PostgresNodeReader on real Postgres, as the app role inside a
 * transaction under the actor context of a staff member whose one region is ROOT, so FAR, the root
 * of the other site, is outside it. The rows are written as the superuser.
 */
final class PostgresNodeReaderBehaviourTest extends TestCase
{
    use NodeReaderBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = self::label(self::ROOT);
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert([...StorageTables::node(self::NODE, self::ROOT, $root), 'version' => self::NODE_VERSION]);
        $superuser->table('nodes')->insert([...StorageTables::node(self::ARCHIVED, self::ROOT, $root), 'lifecycle' => 'archived']);
        $superuser->table('nodes')->insert(StorageTables::node(self::MOUNT, self::ROOT, $root, 'mount', self::NODE));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));
        $superuser->table('sites')->insert(['id' => self::SITE, 'handle' => 'harbour_town', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT]);
        $superuser->table('site_locales')->insert([
            ['site_id' => self::SITE, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::SITE, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('node_routes')->insert([
            ['site_id' => self::SITE, 'locale' => 'da', 'route' => '/', 'node_id' => self::ROOT, 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::SITE, 'locale' => 'da', 'route' => '/nyheder', 'node_id' => self::NODE, 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('entries')->insert([...StorageTables::entry(), 'home_node_id' => self::NODE]);

        foreach ([[self::LIVE, self::NODE, 'live', self::UNTIL], [self::HIDDEN, self::ARCHIVED, 'hidden', null]] as [$placement, $node, $visibility, $until]) {
            $superuser->table('placements')->insert(StorageTables::placement($placement));
            $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node));
            $superuser->table('placement_locales')->insert(StorageTables::placementLocale([
                'placement_id' => $placement,
                'node_id' => $node,
                'slug' => 'harbour-'.substr($placement, -4),
                'visibility' => $visibility,
                'live_until' => $until,
                'canonical' => $visibility === 'live',
            ]));
        }

        $clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath($root))],
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
    protected function nodeReader(): NodeReader
    {
        return new PostgresNodeReader(app(ConnectionResolverInterface::class));
    }
}

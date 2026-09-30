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
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementReader;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Tests\Placements\PlacementReaderBehaviour;
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
 * PlacementReaderBehaviour against PostgresPlacementReader on real Postgres, as the app role inside
 * a transaction under the actor context of a staff member whose region is ROOT. The rows are
 * written as the superuser.
 */
final class PostgresPlacementReaderBehaviourTest extends TestCase
{
    use PlacementReaderBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = StorageTables::label(self::ROOT);
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert([...StorageTables::node(self::NODE, self::ROOT, $root), 'version' => 3]);
        $superuser->table('nodes')->insert(StorageTables::node(self::MOUNT, self::ROOT, $root, 'mount', self::NODE));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));
        $superuser->table('sites')->insert([
            ['id' => self::SITE, 'handle' => 'harbour_town', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
            ['id' => self::FAR_SITE, 'handle' => 'far_town', 'root_node_id' => self::FAR, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('site_locales')->insert([
            ['site_id' => self::SITE, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::SITE, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::FAR_SITE, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('entries')->insert([...StorageTables::entry(self::ENTRY), 'home_node_id' => self::NODE, 'version' => 2]);

        foreach ([[self::PLACED, 4, self::NODE], [self::OLD, 1, self::NODE], [self::FAR_PLACED, 1, self::FAR]] as [$placement, $version, $node]) {
            $superuser->table('placements')->insert([...StorageTables::placement($placement, self::ENTRY), 'version' => $version]);
            $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node));
        }

        $locale = static fn (string $placement, string $node, string $locale, string $slug, string $visibility, bool $canonical, ?string $from = null): array => StorageTables::placementLocale([
            'placement_id' => $placement, 'entry_id' => self::ENTRY, 'node_id' => $node, 'locale' => $locale, 'slug' => $slug,
            'visibility' => $visibility, 'live_from' => $from, 'canonical' => $canonical,
        ]);
        $superuser->table('placement_locales')->insert([
            $locale(self::PLACED, self::NODE, 'da', 'harbour', 'live', true, self::LIVE_FROM),
            $locale(self::PLACED, self::NODE, 'en', 'harbour', 'hidden', false),
            $locale(self::OLD, self::NODE, 'da', 'old', 'withdrawn', false),
            $locale(self::FAR_PLACED, self::FAR, 'da', 'harbour', 'hidden', false),
        ]);

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
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
    protected function placementReader(): PlacementReader
    {
        return new PostgresPlacementReader(app(ConnectionResolverInterface::class));
    }
}

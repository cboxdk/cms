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
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Routing\Adapter\PostgresRouteReader;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Tests\Routing\RouteReaderBehaviour;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;

/**
 * RouteReaderBehaviour against PostgresRouteReader on real Postgres, as the app role inside a
 * transaction under the actor context of a staff member whose regions are ROOT and FAR. The rows
 * are written as the superuser, ENTRY's released row in the workbench's fixture measurement table.
 */
final class PostgresRouteReaderBehaviourTest extends TestCase
{
    use RealPostgres;
    use RouteReaderBehaviour;

    private const string CHANGESET = '019cd79e-4600-7000-8000-0000000035f1';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = StorageTables::label(self::ROOT);
        $far = StorageTables::label(self::FAR);
        $section = $root.'.'.StorageTables::label(self::SECTION);
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::SECTION, self::ROOT, $root));
        $superuser->table('nodes')->insert(StorageTables::node(self::SPORT, self::SECTION, $section));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::MOUNT, self::FAR, $far, 'mount', self::SECTION));
        $superuser->table('sites')->insert([
            ['id' => self::NORTH, 'handle' => 'north', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
            ['id' => self::SOUTH, 'handle' => 'south', 'root_node_id' => self::FAR, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('site_locales')->insert([
            ['site_id' => self::NORTH, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::NORTH, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::SOUTH, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
        ]);
        $route = static fn (string $site, string $locale, string $route, string $node): array => ['site_id' => $site, 'locale' => $locale, 'route' => $route, 'node_id' => $node, 'created_at' => StorageTables::CREATED_AT];
        $superuser->table('node_routes')->insert([
            $route(self::NORTH, 'da', '/', self::ROOT),
            $route(self::NORTH, 'en', '/', self::ROOT),
            $route(self::NORTH, 'da', '/nyheder', self::SECTION),
            $route(self::NORTH, 'da', '/nyheder/sport', self::SPORT),
            $route(self::SOUTH, 'da', '/', self::FAR),
            $route(self::SOUTH, 'da', '/national', self::MOUNT),
        ]);

        foreach ([self::ENTRY, self::DRAFT] as $entry) {
            $superuser->table('entries')->insert([...StorageTables::entry($entry), 'type_id' => self::TYPE, 'home_node_id' => self::SECTION]);
        }

        $superuser->table('changeset_register')->insert(['changeset_id' => self::CHANGESET, 'retention_class' => 'standard']);
        $revision = (int) $superuser->table('revisions')->insertGetId([
            'entry_id' => self::ENTRY, 'variant' => 'shared', 'rev_no' => 1, 'kind' => 'published', 'schema_version' => 1,
            'changeset_id' => self::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
        ], 'revision_id');
        $superuser->table('variant_heads')->insert(StorageTables::head([
            'entry_id' => self::ENTRY, 'draft_revision_id' => $revision, 'published_revision_id' => $revision, 'release_state' => 'released',
        ]));

        $placed = [
            [self::PLACED, self::ENTRY, self::SECTION, 'harbour', 'live', true, self::LIVE_FROM],
            [self::OLD, self::ENTRY, self::SECTION, 'harbour', 'withdrawn', false, null],
            [self::GONE_B, self::ENTRY, self::SECTION, 'gone', 'withdrawn', false, null],
            [self::GONE_A, self::ENTRY, self::SECTION, 'gone', 'withdrawn', false, null],
            [self::DRAFTED, self::DRAFT, self::SPORT, 'match', 'hidden', true, null],
        ];

        foreach ($placed as [$placement, $entry, $node, $slug, $visibility, $canonical, $from]) {
            $superuser->table('placements')->insert(StorageTables::placement($placement, $entry));
            $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node));
            $superuser->table('placement_locales')->insert(StorageTables::placementLocale([
                'placement_id' => $placement, 'entry_id' => $entry, 'node_id' => $node, 'slug' => $slug,
                'visibility' => $visibility, 'live_from' => $from, 'canonical' => $canonical,
            ]));
        }

        $superuser->table($this->releasedType()->name->table())->insert([
            ...TypeTableColumns::encode($this->releasedType(), self::released()),
            'cms_entry_id' => self::ENTRY, 'cms_locale' => 'shared', 'cms_stage' => 'released', 'cms_home_node' => self::SECTION,
        ]);

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath($root)), new AccessRegion(new NodePath($far))],
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
    protected function routeReader(): RouteReader
    {
        return new PostgresRouteReader(app(ConnectionResolverInterface::class));
    }

    #[Override]
    protected function releasedType(): TypeDefinition
    {
        return app(TypeCatalog::class)->named(new TypeName('app:fixture_measurement')) ?? throw new LogicException('The workbench has no fixture measurement.');
    }
}

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
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteLocaleUse;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use Cbox\Cms\Core\Tests\Structure\SiteLocaleUseBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * SiteLocaleUseBehaviour against PostgresSiteLocaleUse on real Postgres, as the app role inside a
 * transaction under the actor context of a staff member whose one region is OTHER's root, so
 * SITE's placements lie outside it and are still counted, as the owner lookup counts past the
 * regions. The rows are written as the superuser.
 */
final class PostgresSiteLocaleUseBehaviourTest extends TestCase
{
    use RealPostgres;
    use SiteLocaleUseBehaviour;

    private const string ROOT = '0192a0c0-0000-7000-8000-00000000c001';

    private const string SECTION = '0192a0c0-0000-7000-8000-00000000c002';

    private const string OTHER_ROOT = '0192a0c0-0000-7000-8000-00000000c003';

    private const string LIVE = '0192a0c0-0000-7000-8000-00000000c020';

    private const string HIDDEN = '0192a0c0-0000-7000-8000-00000000c021';

    private const string WITHDRAWN = '0192a0c0-0000-7000-8000-00000000c022';

    private const string OTHER_LIVE = '0192a0c0-0000-7000-8000-00000000c023';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::SECTION, self::ROOT, StorageTables::label(self::ROOT)));
        $superuser->table('nodes')->insert(StorageTables::node(self::OTHER_ROOT, kind: 'site'));
        $superuser->table('sites')->insert([
            ['id' => self::SITE, 'handle' => 'north', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
            ['id' => self::OTHER, 'handle' => 'south', 'root_node_id' => self::OTHER_ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('site_locales')->insert([
            ['site_id' => self::SITE, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::SITE, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT],
            ['site_id' => self::OTHER, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('entries')->insert([...StorageTables::entry(), 'home_node_id' => self::SECTION]);

        foreach ([[self::LIVE, self::SECTION, 'live'], [self::HIDDEN, self::ROOT, 'hidden'], [self::WITHDRAWN, self::SECTION, 'withdrawn'], [self::OTHER_LIVE, self::OTHER_ROOT, 'live']] as [$placement, $node, $visibility]) {
            $superuser->table('placements')->insert(StorageTables::placement($placement));
            $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node));
            $superuser->table('placement_locales')->insert(StorageTables::placementLocale([
                'placement_id' => $placement,
                'node_id' => $node,
                'slug' => 'harbour-'.substr($placement, -4),
                'visibility' => $visibility,
                'live_until' => null,
                'canonical' => false,
            ]));
        }

        $clock = new FakeClock;
        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::OTHER_ROOT)))],
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
    protected function siteLocaleUse(): SiteLocaleUse
    {
        return new PostgresSiteLocaleUse(app(ConnectionResolverInterface::class));
    }
}

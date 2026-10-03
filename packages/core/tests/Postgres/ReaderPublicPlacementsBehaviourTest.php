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
use Cbox\Cms\Core\Pipeline\Domain\PublicPlacements;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementReader;
use Cbox\Cms\Core\Placements\Adapter\ReaderPublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\PublicPlacementsBehaviour;
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
 * PublicPlacementsBehaviour against ReaderPublicPlacements over PostgresPlacementReader on real
 * Postgres, as the app role inside a transaction under the actor context of a staff member whose
 * region is the root ROOT above NODE; FAR is a root outside it. The rows are written as the
 * superuser, each placement's locales with the window the behaviour describes.
 */
final class ReaderPublicPlacementsBehaviourTest extends TestCase
{
    use PublicPlacementsBehaviour;
    use RealPostgres;

    private const string ROOT = '0192a0c0-0000-7000-8000-0000000004a1';

    private const string NODE = '0192a0c0-0000-7000-8000-0000000004a2';

    private const string FAR = '0192a0c0-0000-7000-8000-0000000004a3';

    private const string TYPE = '0192a0c0-0000-7000-8000-0000000004c1';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::NODE, self::ROOT, StorageTables::label(self::ROOT)));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));

        foreach ([self::SHOWN_ENTRY, self::SCHEDULED_ENTRY, self::UNSHOWN_ENTRY, self::BARE_ENTRY] as $entry) {
            $superuser->table('entries')->insert([...StorageTables::entry($entry), 'type_id' => self::TYPE, 'home_node_id' => self::NODE]);
        }

        $placements = [
            [self::LIVE, self::SHOWN_ENTRY, 3, self::FAR],
            [self::WITHDRAWN, self::SHOWN_ENTRY, 1, self::NODE],
            [self::SCHEDULED, self::SCHEDULED_ENTRY, 2, self::NODE],
            [self::HIDDEN, self::UNSHOWN_ENTRY, 2, self::NODE],
            [self::EXPIRED, self::UNSHOWN_ENTRY, 1, self::NODE],
        ];

        foreach ($placements as [$placement, $entry, $version, $node]) {
            $superuser->table('placements')->insert([...StorageTables::placement($placement, $entry), 'version' => $version]);
            $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node));
        }

        $locale = static fn (string $placement, string $entry, string $node, string $locale, string $slug, string $visibility, bool $canonical, ?string $from = null, ?string $until = null): array => StorageTables::placementLocale([
            'placement_id' => $placement, 'entry_id' => $entry, 'node_id' => $node, 'locale' => $locale, 'slug' => $slug,
            'visibility' => $visibility, 'live_from' => $from, 'live_until' => $until, 'canonical' => $canonical,
        ]);
        $superuser->table('placement_locales')->insert([
            $locale(self::LIVE, self::SHOWN_ENTRY, self::FAR, 'da', 'harbour', 'live', true, '2026-03-01 08:00:00+00'),
            $locale(self::LIVE, self::SHOWN_ENTRY, self::FAR, 'en', 'harbour', 'hidden', false),
            $locale(self::WITHDRAWN, self::SHOWN_ENTRY, self::NODE, 'da', 'old-harbour', 'withdrawn', false),
            $locale(self::SCHEDULED, self::SCHEDULED_ENTRY, self::NODE, 'da', 'quay', 'scheduled', false, '2026-04-01 08:00:00+00'),
            $locale(self::HIDDEN, self::UNSHOWN_ENTRY, self::NODE, 'da', 'pier', 'hidden', false),
            $locale(self::EXPIRED, self::UNSHOWN_ENTRY, self::NODE, 'da', 'jetty', 'live', false, '2026-02-01 08:00:00+00', '2026-03-01 08:00:00+00'),
        ]);

        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

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
    protected function publicPlacements(): PublicPlacements
    {
        return new ReaderPublicPlacements(new PostgresPlacementReader(app(ConnectionResolverInterface::class)), new FakeClock(new DateTimeImmutable(self::NOW)));
    }
}

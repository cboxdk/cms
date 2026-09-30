<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every RouteReader does, run against PostgresRouteReader and FakeRouteReader, so the fake
 * the resolution's action tests use cannot drift from what path.resolve reads on Postgres
 * (GUARDRAILS 9).
 *
 * The world: the site NORTH ("north") on the root ROOT publishes in da and en, with the routes "/"
 * to ROOT in both, "/nyheder" to the section SECTION and "/nyheder/sport" to the section SPORT
 * below it in da. The site SOUTH ("south") on the root FAR publishes in da, with "/" to FAR and
 * "/national" to MOUNT, a mount of SECTION below FAR. ENTRY, of the type TYPE, active and released,
 * is placed by PLACED below SECTION in da with the slug "harbour", live since LIVE_FROM and
 * canonical, and by OLD below SECTION in da with the same slug, withdrawn. GONE_B and GONE_A are
 * two withdrawn placements of ENTRY with the slug "gone". DRAFT, of the type TYPE, active and with
 * no head, is placed by DRAFTED below SPORT in da with the slug "match", hidden and canonical.
 */
trait RouteReaderBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000035a1';

    public const string SECTION = '0192a0c0-0000-7000-8000-0000000035a2';

    public const string SPORT = '0192a0c0-0000-7000-8000-0000000035a3';

    public const string FAR = '0192a0c0-0000-7000-8000-0000000035a4';

    public const string MOUNT = '0192a0c0-0000-7000-8000-0000000035a5';

    public const string NORTH = '0192a0c0-0000-7000-8000-0000000035b1';

    public const string SOUTH = '0192a0c0-0000-7000-8000-0000000035b2';

    public const string TYPE = '0192a0c0-0000-7000-8000-0000000035d1';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000035e1';

    public const string DRAFT = '0192a0c0-0000-7000-8000-0000000035e2';

    public const string PLACED = '0192a0c0-0000-7000-8000-0000000035c1';

    public const string OLD = '0192a0c0-0000-7000-8000-0000000035c2';

    public const string GONE_A = '0192a0c0-0000-7000-8000-0000000035c3';

    public const string GONE_B = '0192a0c0-0000-7000-8000-0000000035c4';

    public const string DRAFTED = '0192a0c0-0000-7000-8000-0000000035c5';

    public const string LIVE_FROM = '2026-03-01T06:00:00.000000+00:00';

    /**
     * The reader under test, knowing the world above.
     */
    abstract protected function routeReader(): RouteReader;

    #[Test]
    public function it_finds_the_longest_route_of_the_site_in_the_locale_that_is_a_prefix_of_the_path(): void
    {
        $reader = $this->routeReader();
        $north = new SiteHandle('north');
        $da = new Locale('da');
        $site = SiteId::fromString(self::NORTH);

        Assert::assertEquals(new SiteRoute($site, true, new RouteMatch('/nyheder', NodeId::fromString(self::SECTION), NodeKind::Section, null)), $reader->route($north, $da, new RequestPath('/nyheder/harbour')));
        Assert::assertEquals(new SiteRoute($site, true, new RouteMatch('/nyheder/sport', NodeId::fromString(self::SPORT), NodeKind::Section, null)), $reader->route($north, $da, new RequestPath('/nyheder/sport/match')));
        Assert::assertEquals(new SiteRoute($site, true, new RouteMatch('/nyheder', NodeId::fromString(self::SECTION), NodeKind::Section, null)), $reader->route($north, $da, new RequestPath('/nyheder')));
        Assert::assertEquals(new SiteRoute($site, true, new RouteMatch('/', NodeId::fromString(self::ROOT), NodeKind::Site, null)), $reader->route($north, $da, new RequestPath('/nyhederne/harbour')));
        Assert::assertEquals(new SiteRoute($site, true, new RouteMatch('/', NodeId::fromString(self::ROOT), NodeKind::Site, null)), $reader->route($north, new Locale('en'), new RequestPath('/nyheder/harbour')));
    }

    #[Test]
    public function it_reads_a_mount_with_its_source(): void
    {
        Assert::assertEquals(
            new SiteRoute(SiteId::fromString(self::SOUTH), true, new RouteMatch('/national', NodeId::fromString(self::MOUNT), NodeKind::Mount, NodeId::fromString(self::SECTION))),
            $this->routeReader()->route(new SiteHandle('south'), new Locale('da'), new RequestPath('/national/harbour')),
        );
    }

    #[Test]
    public function it_says_when_the_site_does_not_publish_in_the_locale_and_when_no_site_has_the_handle(): void
    {
        $reader = $this->routeReader();

        Assert::assertEquals(new SiteRoute(SiteId::fromString(self::SOUTH), false, null), $reader->route(new SiteHandle('south'), new Locale('en'), new RequestPath('/national/harbour')));
        Assert::assertNull($reader->route(new SiteHandle('west'), new Locale('da'), new RequestPath('/')));
    }

    #[Test]
    public function it_reads_the_placement_that_is_not_withdrawn_with_its_entry_and_head(): void
    {
        Assert::assertEquals(
            new PlacementMatch(
                PlacementId::fromString(self::PLACED),
                EntryId::fromString(self::ENTRY),
                Visibility::Live,
                new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)),
                true,
                TypeId::fromString(self::TYPE),
                EntryLifecycle::Active,
                ReleaseState::Released,
            ),
            $this->routeReader()->placement(NodeId::fromString(self::SECTION), new Locale('da'), new Slug('harbour')),
        );
    }

    #[Test]
    public function it_reads_a_withdrawn_placement_when_no_other_has_the_slug_the_lowest_id_first(): void
    {
        $found = $this->routeReader()->placement(NodeId::fromString(self::SECTION), new Locale('da'), new Slug('gone'));

        Assert::assertSame(self::GONE_A, $found?->placement->toString());
        Assert::assertSame(Visibility::Withdrawn, $found->visibility);
        Assert::assertFalse($found->canonical);
    }

    #[Test]
    public function it_reads_an_entry_without_a_head_with_no_release_state(): void
    {
        $found = $this->routeReader()->placement(NodeId::fromString(self::SPORT), new Locale('da'), new Slug('match'));

        Assert::assertSame(self::DRAFTED, $found?->placement->toString());
        Assert::assertSame(Visibility::Hidden, $found->visibility);
        Assert::assertNull($found->window);
        Assert::assertSame(EntryLifecycle::Active, $found->lifecycle);
        Assert::assertNull($found->release);
    }

    #[Test]
    public function it_reads_no_placement_for_another_node_locale_or_slug(): void
    {
        $reader = $this->routeReader();

        Assert::assertNull($reader->placement(NodeId::fromString(self::SECTION), new Locale('en'), new Slug('harbour')));
        Assert::assertNull($reader->placement(NodeId::fromString(self::SECTION), new Locale('da'), new Slug('nothing')));
        Assert::assertNull($reader->placement(NodeId::fromString(self::MOUNT), new Locale('da'), new Slug('harbour')));
    }

    #[Test]
    public function it_reads_the_canonical_placement_with_its_site_and_route(): void
    {
        $reader = $this->routeReader();

        Assert::assertEquals(
            new CanonicalMatch(PlacementId::fromString(self::PLACED), NodeId::fromString(self::SECTION), new Slug('harbour'), new SiteHandle('north'), '/nyheder'),
            $reader->canonical(EntryId::fromString(self::ENTRY), new Locale('da')),
        );
        Assert::assertEquals(
            new CanonicalMatch(PlacementId::fromString(self::DRAFTED), NodeId::fromString(self::SPORT), new Slug('match'), new SiteHandle('north'), '/nyheder/sport'),
            $reader->canonical(EntryId::fromString(self::DRAFT), new Locale('da')),
        );
        Assert::assertNull($reader->canonical(EntryId::fromString(self::ENTRY), new Locale('en')));
    }
}

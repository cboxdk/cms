<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldReader;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\OwnPlacement;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteRedirect;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
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
 * two withdrawn placements of ENTRY with the slug "gone", and PIER a withdrawn one with the slug
 * "pier". The slug history of SECTION in da (PRD 5.9) says PLACED had the slugs "quay" and "pier"
 * before, and that GONE_A had "harbour"; the route history of north in da says SECTION had the
 * route "/nyt" and SPORT "/nyt/sport" before, and of south in da that MOUNT had "/landsdaekkende".
 * DRAFT, of the type TYPE, active and with
 * no head, is placed by DRAFTED below SPORT in da with the slug "match", hidden and canonical.
 * ENTRY has a released row with the fields released() in the table of releasedType(), the
 * workbench's fixture measurement; DRAFT has none. The site south hides ENTRY on MOUNT. In the
 * table of localizedType(), a type with localization variants, ENTRY has the released row of da
 * with the fields localized(), and none of en.
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

    public const string LOCALIZED_TYPE = '0192a0c0-0000-7000-8000-0000000035d2';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000035e1';

    public const string DRAFT = '0192a0c0-0000-7000-8000-0000000035e2';

    public const string PLACED = '0192a0c0-0000-7000-8000-0000000035c1';

    public const string OLD = '0192a0c0-0000-7000-8000-0000000035c2';

    public const string GONE_A = '0192a0c0-0000-7000-8000-0000000035c3';

    public const string GONE_B = '0192a0c0-0000-7000-8000-0000000035c4';

    public const string DRAFTED = '0192a0c0-0000-7000-8000-0000000035c5';

    public const string PIER = '0192a0c0-0000-7000-8000-0000000035c6';

    public const string LIVE_FROM = '2026-03-01T06:00:00.000000+00:00';

    /**
     * The reader under test, knowing the world above.
     */
    abstract protected function routeReader(): RouteReader;

    /**
     * The type whose table holds ENTRY's released row.
     */
    abstract protected function releasedType(): TypeDefinition;

    /**
     * The type with localization variants whose table holds ENTRY's released row of da.
     */
    abstract protected function localizedType(): TypeDefinition;

    /**
     * The fields of ENTRY's released row.
     */
    public static function released(): FieldValues
    {
        return EntryFields::measurement(station: 'harbour-1');
    }

    /**
     * The fields of ENTRY's released row of da in the localized type's table: the translatable
     * title and the shared rank.
     */
    public static function localized(): FieldValues
    {
        return EntryFields::of(['title' => new TextValue('Havnen'), 'rank' => new IntegerValue(3)]);
    }

    /**
     * A type with localization variants, test:localized, for the localized rows: a translatable
     * text and a shared integer.
     */
    public static function localizedDefinition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::LOCALIZED_TYPE),
            new TypeName('test:localized'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::Variants, true),
            [],
            [
                new FieldDefinition(null, new FieldHandle('rank'), 'integer', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('rank', 'integer', false, [])),
                new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition('title', 'text', true, []), translatable: true),
            ],
        );
    }

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
    public function it_reads_whether_the_mount_hides_the_entry_when_the_slug_is_looked_up_through_a_mount(): void
    {
        $reader = $this->routeReader();
        $section = NodeId::fromString(self::SECTION);
        $da = new Locale('da');

        $direct = $reader->placement($section, $da, new Slug('harbour'));
        $mounted = $reader->placement($section, $da, new Slug('harbour'), NodeId::fromString(self::MOUNT));
        $elsewhere = $reader->placement($section, $da, new Slug('harbour'), NodeId::fromString(self::SPORT));

        Assert::assertFalse($direct?->hiddenOnMount);
        Assert::assertTrue($mounted?->hiddenOnMount);
        Assert::assertSame(self::PLACED, $mounted->placement->toString());
        Assert::assertFalse($elsewhere?->hiddenOnMount);
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
    public function it_reads_the_placement_that_had_the_slug_before_with_its_current_slug_after_the_live_lookup_and_before_a_withdrawn_one(): void
    {
        $reader = $this->routeReader();
        $section = NodeId::fromString(self::SECTION);
        $da = new Locale('da');
        $live = new PlacementMatch(
            PlacementId::fromString(self::PLACED),
            EntryId::fromString(self::ENTRY),
            Visibility::Live,
            new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)),
            true,
            TypeId::fromString(self::TYPE),
            EntryLifecycle::Active,
            ReleaseState::Released,
        );

        Assert::assertEquals(new PlacementMatch($live->placement, $live->entry, $live->visibility, $live->window, true, $live->type, $live->lifecycle, $live->release, false, new Slug('harbour')), $reader->placement($section, $da, new Slug('quay')));
        Assert::assertEquals(new PlacementMatch($live->placement, $live->entry, $live->visibility, $live->window, true, $live->type, $live->lifecycle, $live->release, false, new Slug('harbour')), $reader->placement($section, $da, new Slug('pier')));
        Assert::assertEquals($live, $reader->placement($section, $da, new Slug('harbour')));
        Assert::assertSame(self::GONE_A, $reader->placement($section, $da, new Slug('gone'))?->placement->toString());
        Assert::assertNull($reader->placement($section, new Locale('en'), new Slug('quay')));
        Assert::assertNull($reader->placement(NodeId::fromString(self::SPORT), $da, new Slug('quay')));
        Assert::assertTrue($reader->placement($section, $da, new Slug('quay'), NodeId::fromString(self::MOUNT))?->hiddenOnMount);
    }

    #[Test]
    public function it_reads_the_prefix_redirect_of_the_longest_route_a_node_had_before_with_the_nodes_current_route(): void
    {
        $reader = $this->routeReader();
        $north = SiteId::fromString(self::NORTH);
        $da = new Locale('da');

        Assert::assertEquals(new RouteRedirect('/nyt/sport', NodeId::fromString(self::SPORT), NodeKind::Section, null, '/nyheder/sport'), $reader->redirectedRoute($north, $da, new RequestPath('/nyt/sport/match')));
        Assert::assertEquals(new RouteRedirect('/nyt', NodeId::fromString(self::SECTION), NodeKind::Section, null, '/nyheder'), $reader->redirectedRoute($north, $da, new RequestPath('/nyt/harbour/more')));
        Assert::assertEquals(new RouteRedirect('/nyt', NodeId::fromString(self::SECTION), NodeKind::Section, null, '/nyheder'), $reader->redirectedRoute($north, $da, new RequestPath('/nyt')));
        Assert::assertEquals(new RouteRedirect('/landsdaekkende', NodeId::fromString(self::MOUNT), NodeKind::Mount, NodeId::fromString(self::SECTION), '/national'), $reader->redirectedRoute(SiteId::fromString(self::SOUTH), $da, new RequestPath('/landsdaekkende/harbour')));
        Assert::assertNull($reader->redirectedRoute($north, $da, new RequestPath('/nyheder/harbour')));
        Assert::assertNull($reader->redirectedRoute($north, new Locale('en'), new RequestPath('/nyt/harbour')));
        Assert::assertNull($reader->redirectedRoute(SiteId::fromString(self::SOUTH), $da, new RequestPath('/nyt/harbour')));
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

    #[Test]
    public function it_reads_the_sites_own_placement_of_an_entry_with_its_route_and_none_through_a_mount_or_withdrawn(): void
    {
        $reader = $this->routeReader();
        $da = new Locale('da');
        $entry = EntryId::fromString(self::ENTRY);

        Assert::assertEquals(
            new OwnPlacement(PlacementId::fromString(self::PLACED), NodeId::fromString(self::SECTION), '/nyheder', new Slug('harbour'), Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM))),
            $reader->own(SiteId::fromString(self::NORTH), $entry, $da),
        );
        Assert::assertEquals(
            new OwnPlacement(PlacementId::fromString(self::DRAFTED), NodeId::fromString(self::SPORT), '/nyheder/sport', new Slug('match'), Visibility::Hidden, null),
            $reader->own(SiteId::fromString(self::NORTH), EntryId::fromString(self::DRAFT), $da),
        );
        Assert::assertNull($reader->own(SiteId::fromString(self::SOUTH), $entry, $da));
        Assert::assertNull($reader->own(SiteId::fromString(self::NORTH), $entry, new Locale('en')));
    }

    #[Test]
    public function it_reads_the_fields_of_the_released_row_of_an_entry_and_none_without_one(): void
    {
        $reader = $this->routeReader();
        $fields = $reader->released($this->releasedType(), EntryId::fromString(self::ENTRY), new Locale('da'));
        Assert::assertInstanceOf(FieldValues::class, $fields);
        $read = FieldReader::of($fields->own);

        Assert::assertSame(['harbour-1', 3, false, 'fixture_celsius'], [$read->text('fixture_station'), $read->integer('fixture_samples'), $read->boolean('fixture_calibrated'), $read->text('fixture_scale')]);
        Assert::assertSame('S-7', $read->group('fixture_sensor')->text('fixture_sensor_code'));
        Assert::assertNull($reader->released($this->releasedType(), EntryId::fromString(self::DRAFT), new Locale('da')));
        Assert::assertEquals($fields, $reader->released($this->releasedType(), EntryId::fromString(self::ENTRY), new Locale('en')));
    }

    #[Test]
    public function it_reads_the_released_row_of_the_locale_for_a_type_with_localization_variants(): void
    {
        $reader = $this->routeReader();
        $entry = EntryId::fromString(self::ENTRY);
        $fields = $reader->released($this->localizedType(), $entry, new Locale('da'));
        Assert::assertInstanceOf(FieldValues::class, $fields);
        $read = FieldReader::of($fields->own);

        Assert::assertSame(['Havnen', 3], [$read->text('title'), $read->integer('rank')]);
        Assert::assertNull($reader->released($this->localizedType(), $entry, new Locale('en')));
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every PlacementReader does, run against PostgresPlacementReader and FakePlacementReader, so
 * the fake the placement actions' tests use cannot drift from what the commands read on Postgres
 * (GUARDRAILS 9).
 *
 * The world: the site SITE on the root ROOT publishes in da and en; below ROOT are the section NODE
 * at version 3 and the mount MOUNT of NODE. The site FAR_SITE on the root FAR lies outside the
 * actor's regions. The entry ENTRY is homed on NODE at version 2. PLACED puts ENTRY below NODE at
 * version 4: in da with the slug "harbour", live since LIVE_FROM and canonical, and in en with the
 * slug "harbour", hidden. OLD puts ENTRY below NODE at version 1, withdrawn in da with the slug
 * "old". FAR_PLACED puts ENTRY below FAR at version 1, hidden in da with the slug "harbour".
 */
trait PlacementReaderBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000003a1';

    public const string NODE = '0192a0c0-0000-7000-8000-0000000003a2';

    public const string MOUNT = '0192a0c0-0000-7000-8000-0000000003a3';

    public const string FAR = '0192a0c0-0000-7000-8000-0000000003a4';

    public const string UNKNOWN = '0192a0c0-0000-7000-8000-0000000003a9';

    public const string SITE = '0192a0c0-0000-7000-8000-0000000003b1';

    public const string FAR_SITE = '0192a0c0-0000-7000-8000-0000000003b2';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000003e1';

    public const string PLACED = '0192a0c0-0000-7000-8000-0000000003c1';

    public const string OLD = '0192a0c0-0000-7000-8000-0000000003c2';

    public const string FAR_PLACED = '0192a0c0-0000-7000-8000-0000000003c3';

    public const string LIVE_FROM = '2026-03-01T06:00:00.000000+00:00';

    /**
     * The reader under test, knowing the world above, with the actor's regions reaching ROOT.
     */
    abstract protected function placementReader(): PlacementReader;

    #[Test]
    public function it_reads_a_node_the_actor_reaches_with_its_path_and_whether_it_is_a_mount(): void
    {
        $reader = $this->placementReader();
        $root = str_replace('-', '', self::ROOT);

        Assert::assertEquals(new StoredNode(NodeId::fromString(self::NODE), new AggregateVersion(3), new NodePath($root.'.'.str_replace('-', '', self::NODE)), false), $reader->node(NodeId::fromString(self::NODE)));
        Assert::assertTrue($reader->node(NodeId::fromString(self::MOUNT))?->mount);
        Assert::assertNull($reader->node(NodeId::fromString(self::FAR)));
        Assert::assertNull($reader->node(NodeId::fromString(self::UNKNOWN)));
    }

    #[Test]
    public function it_reads_a_site_with_its_root_and_its_locales_in_order(): void
    {
        $reader = $this->placementReader();

        Assert::assertEquals(
            new StoredSite(SiteId::fromString(self::SITE), new AggregateVersion(1), new NodePath(str_replace('-', '', self::ROOT)), [new Locale('da'), new Locale('en')]),
            $reader->site(SiteId::fromString(self::SITE)),
        );
        Assert::assertNull($reader->site(SiteId::fromString(self::UNKNOWN)));
    }

    #[Test]
    public function it_reads_an_entry_s_version(): void
    {
        $reader = $this->placementReader();

        Assert::assertEquals(new AggregateVersion(2), $reader->entry(EntryId::fromString(self::ENTRY)));
        Assert::assertNull($reader->entry(EntryId::fromString(self::UNKNOWN)));
    }

    #[Test]
    public function it_reads_a_placement_below_a_node_the_actor_reaches_with_its_locales_in_order(): void
    {
        $reader = $this->placementReader();

        Assert::assertEquals(new StoredPlacement(
            PlacementId::fromString(self::PLACED),
            EntryId::fromString(self::ENTRY),
            NodeId::fromString(self::NODE),
            new AggregateVersion(4),
            [
                new StoredPlacementLocale(new Locale('da'), new Slug('harbour'), Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)), true),
                new StoredPlacementLocale(new Locale('en'), new Slug('harbour'), Visibility::Hidden, null, false),
            ],
        ), $reader->placement(PlacementId::fromString(self::PLACED)));
        Assert::assertNull($reader->placement(PlacementId::fromString(self::FAR_PLACED)));
        Assert::assertNull($reader->placement(PlacementId::fromString(self::UNKNOWN)));
    }

    #[Test]
    public function it_reads_a_placement_s_version_past_the_actor_s_regions(): void
    {
        $reader = $this->placementReader();

        Assert::assertEquals(new AggregateVersion(4), $reader->placementVersion(PlacementId::fromString(self::PLACED)));
        Assert::assertEquals(new AggregateVersion(1), $reader->placementVersion(PlacementId::fromString(self::FAR_PLACED)));
        Assert::assertNull($reader->placementVersion(PlacementId::fromString(self::UNKNOWN)));
    }

    #[Test]
    public function it_says_a_slug_is_taken_only_by_a_placement_that_is_not_withdrawn(): void
    {
        $reader = $this->placementReader();
        $node = NodeId::fromString(self::NODE);

        Assert::assertTrue($reader->slugTaken($node, new Locale('da'), new Slug('harbour')));
        Assert::assertTrue($reader->slugTaken($node, new Locale('en'), new Slug('harbour')));
        Assert::assertFalse($reader->slugTaken($node, new Locale('da'), new Slug('old')));
        Assert::assertFalse($reader->slugTaken($node, new Locale('da'), new Slug('harbour-2')));
        Assert::assertFalse($reader->slugTaken(NodeId::fromString(self::MOUNT), new Locale('da'), new Slug('harbour')));
    }

    #[Test]
    public function it_reads_every_placement_of_an_entry_in_a_locale_past_the_actor_s_regions(): void
    {
        $reader = $this->placementReader();
        $entry = EntryId::fromString(self::ENTRY);

        Assert::assertEquals(new LocalePlacements($entry, new Locale('da'), [
            new PlacementState(PlacementId::fromString(self::PLACED), new AggregateVersion(4), Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)), true),
            new PlacementState(PlacementId::fromString(self::OLD), new AggregateVersion(1), Visibility::Withdrawn, TimeWindow::always(), false),
            new PlacementState(PlacementId::fromString(self::FAR_PLACED), new AggregateVersion(1), Visibility::Hidden, null, false),
        ]), $reader->placements($entry, new Locale('da')));
        Assert::assertEquals(new LocalePlacements($entry, new Locale('de'), []), $reader->placements($entry, new Locale('de')));
    }

    #[Test]
    public function it_reads_every_placement_of_an_entry_in_every_locale_in_the_order_of_the_locales(): void
    {
        $reader = $this->placementReader();
        $entry = EntryId::fromString(self::ENTRY);

        Assert::assertEquals([
            new LocalePlacements($entry, new Locale('da'), [
                new PlacementState(PlacementId::fromString(self::PLACED), new AggregateVersion(4), Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)), true),
                new PlacementState(PlacementId::fromString(self::OLD), new AggregateVersion(1), Visibility::Withdrawn, TimeWindow::always(), false),
                new PlacementState(PlacementId::fromString(self::FAR_PLACED), new AggregateVersion(1), Visibility::Hidden, null, false),
            ]),
            new LocalePlacements($entry, new Locale('en'), [
                new PlacementState(PlacementId::fromString(self::PLACED), new AggregateVersion(4), Visibility::Hidden, null, false),
            ]),
        ], $reader->everyLocale($entry));
        Assert::assertSame([], $reader->everyLocale(EntryId::fromString(self::UNKNOWN)));
    }
}

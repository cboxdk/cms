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
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Tests\Placements\Fakes\FakePlacementReader;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PlacementReaderBehaviour against the fake the placement actions' tests use.
 */
final class FakePlacementReaderBehaviourTest extends TestCase
{
    use PlacementReaderBehaviour;

    #[Override]
    protected function placementReader(): PlacementReader
    {
        $root = str_replace('-', '', self::ROOT);
        $node = NodeId::fromString(self::NODE);
        $entry = EntryId::fromString(self::ENTRY);
        $da = new Locale('da');

        return new FakePlacementReader()
            ->withNode(new StoredNode(NodeId::fromString(self::ROOT), new AggregateVersion(1), new NodePath($root), false))
            ->withNode(new StoredNode($node, new AggregateVersion(3), new NodePath($root.'.'.str_replace('-', '', self::NODE)), false))
            ->withNode(new StoredNode(NodeId::fromString(self::MOUNT), new AggregateVersion(1), new NodePath($root.'.'.str_replace('-', '', self::MOUNT)), true))
            ->withNode(new StoredNode(NodeId::fromString(self::FAR), new AggregateVersion(1), new NodePath(str_replace('-', '', self::FAR)), false))
            ->unreached(NodeId::fromString(self::FAR))
            ->withSite(new StoredSite(SiteId::fromString(self::SITE), new AggregateVersion(1), new NodePath($root), [$da, new Locale('en')]))
            ->withSite(new StoredSite(SiteId::fromString(self::FAR_SITE), new AggregateVersion(1), new NodePath(str_replace('-', '', self::FAR)), [$da]))
            ->withEntry($entry, new AggregateVersion(2))
            ->withPlacement(new StoredPlacement(PlacementId::fromString(self::PLACED), $entry, $node, new AggregateVersion(4), [
                new StoredPlacementLocale($da, new Slug('harbour'), Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)), true),
                new StoredPlacementLocale(new Locale('en'), new Slug('harbour'), Visibility::Hidden, null, false),
            ]))
            ->withPlacement(new StoredPlacement(PlacementId::fromString(self::OLD), $entry, $node, new AggregateVersion(1), [
                new StoredPlacementLocale($da, new Slug('old'), Visibility::Withdrawn, TimeWindow::always(), false),
            ]))
            ->withPlacement(new StoredPlacement(PlacementId::fromString(self::FAR_PLACED), $entry, NodeId::fromString(self::FAR), new AggregateVersion(1), [
                new StoredPlacementLocale($da, new Slug('harbour'), Visibility::Hidden, null, false),
            ]));
    }
}

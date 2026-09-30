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
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Tests\Routing\Fakes\FakeRouteReader;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RouteReaderBehaviour against the fake the resolution's action tests use.
 */
final class FakeRouteReaderBehaviourTest extends TestCase
{
    use RouteReaderBehaviour;

    #[Override]
    protected function routeReader(): RouteReader
    {
        $north = new SiteHandle('north');
        $south = new SiteHandle('south');
        $da = new Locale('da');
        $en = new Locale('en');
        $section = NodeId::fromString(self::SECTION);
        $sport = NodeId::fromString(self::SPORT);
        $entry = EntryId::fromString(self::ENTRY);
        $type = TypeId::fromString(self::TYPE);
        $withdrawn = static fn (string $placement): PlacementMatch => new PlacementMatch(PlacementId::fromString($placement), $entry, Visibility::Withdrawn, null, false, $type, EntryLifecycle::Active, ReleaseState::Released);

        return new FakeRouteReader()
            ->withSite($north, SiteId::fromString(self::NORTH), [$da, $en])
            ->withSite($south, SiteId::fromString(self::SOUTH), [$da])
            ->withNode(NodeId::fromString(self::ROOT), NodeKind::Site, $north)
            ->withNode($section, NodeKind::Section, $north)
            ->withNode($sport, NodeKind::Section, $north)
            ->withNode(NodeId::fromString(self::FAR), NodeKind::Site, $south)
            ->withNode(NodeId::fromString(self::MOUNT), NodeKind::Mount, $south, $section)
            ->withRoute($north, $da, '/', NodeId::fromString(self::ROOT))
            ->withRoute($north, $en, '/', NodeId::fromString(self::ROOT))
            ->withRoute($north, $da, '/nyheder', $section)
            ->withRoute($north, $da, '/nyheder/sport', $sport)
            ->withRoute($south, $da, '/', NodeId::fromString(self::FAR))
            ->withRoute($south, $da, '/national', NodeId::fromString(self::MOUNT))
            ->withPlacement($section, $da, new Slug('harbour'), $withdrawn(self::OLD))
            ->withPlacement($section, $da, new Slug('harbour'), new PlacementMatch(PlacementId::fromString(self::PLACED), $entry, Visibility::Live, new TimeWindow(new DateTimeImmutable(self::LIVE_FROM)), true, $type, EntryLifecycle::Active, ReleaseState::Released))
            ->withPlacement($section, $da, new Slug('gone'), $withdrawn(self::GONE_B))
            ->withPlacement($section, $da, new Slug('gone'), $withdrawn(self::GONE_A))
            ->withPlacement($sport, $da, new Slug('match'), new PlacementMatch(PlacementId::fromString(self::DRAFTED), EntryId::fromString(self::DRAFT), Visibility::Hidden, null, true, $type, EntryLifecycle::Active, null))
            ->withReleased($this->releasedType()->id, $entry, self::released());
    }

    #[Override]
    protected function releasedType(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::TYPE),
            new TypeName('app:fixture_measurement'),
            1,
            new TypeCapabilities(History::None, Stages::None, Localization::None, true),
            [],
            [],
        );
    }
}

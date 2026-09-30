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
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Tests\Routing\Fakes\FakeRouteReader;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use DateTimeImmutable;

/**
 * path.resolve's action with fakes (GUARDRAILS 9): a FakeRouteReader, the configured sites and a
 * type catalog, at NOW on a FakeClock.
 *
 * The structure: the site north (NORTH_SITE, origin https://north.example, also served at
 * www.north.example) publishes in da and en, with "/" to ROOT and "/nyheder" to SECTION in da and
 * no route in en. The site south (SOUTH_SITE, origin https://south.example) publishes in da, with "/"
 * to FAR, "/national" to MOUNT, a mount of SECTION, and "/lokalt" to LOCAL, a section of its own,
 * and the storage folder STORE, which has no route.
 * The site west is configured at west.example, but no site has its handle. The types: ARTICLE has
 * stages and URLs, READING has no stages, NOTE has no URLs. place() adds a placement of an entry.
 */
final readonly class ResolveWorld
{
    public const string NOW = '2026-03-10T12:00:00Z';

    public const string NORTH_SITE = '01936f5e-8a2b-7c3d-9e4f-000000003501';

    public const string SOUTH_SITE = '01936f5e-8a2b-7c3d-9e4f-000000003502';

    public const string ROOT = '01936f5e-8a2b-7c3d-9e4f-000000003511';

    public const string SECTION = '01936f5e-8a2b-7c3d-9e4f-000000003512';

    public const string FAR = '01936f5e-8a2b-7c3d-9e4f-000000003513';

    public const string MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000003514';

    public const string LOCAL = '01936f5e-8a2b-7c3d-9e4f-000000003515';

    public const string STORE = '01936f5e-8a2b-7c3d-9e4f-000000003516';

    public const string ARTICLE = '01936f5e-8a2b-7c3d-9e4f-000000003521';

    public const string READING = '01936f5e-8a2b-7c3d-9e4f-000000003522';

    public const string NOTE = '01936f5e-8a2b-7c3d-9e4f-000000003523';

    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000003531';

    public const string PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000003541';

    public const string SOUTH_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000003542';

    public FakeRouteReader $reader;

    public FakeClock $clock;

    public function __construct()
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $north = new SiteHandle('north');
        $south = new SiteHandle('south');
        $da = new Locale('da');
        $section = NodeId::fromString(self::SECTION);

        $this->reader = new FakeRouteReader()
            ->withSite($north, SiteId::fromString(self::NORTH_SITE), [$da, new Locale('en')])
            ->withSite($south, SiteId::fromString(self::SOUTH_SITE), [$da])
            ->withNode(NodeId::fromString(self::ROOT), NodeKind::Site, $north)
            ->withNode($section, NodeKind::Section, $north)
            ->withNode(NodeId::fromString(self::FAR), NodeKind::Site, $south)
            ->withNode(NodeId::fromString(self::MOUNT), NodeKind::Mount, $south, $section)
            ->withNode(NodeId::fromString(self::LOCAL), NodeKind::Section, $south)
            ->withNode(NodeId::fromString(self::STORE), NodeKind::Storage, $south)
            ->withRoute($north, $da, '/', NodeId::fromString(self::ROOT))
            ->withRoute($north, $da, '/nyheder', $section)
            ->withRoute($south, $da, '/', NodeId::fromString(self::FAR))
            ->withRoute($south, $da, '/national', NodeId::fromString(self::MOUNT))
            ->withRoute($south, $da, '/lokalt', NodeId::fromString(self::LOCAL));
    }

    /**
     * A window of the hours around NOW, either end open when null.
     */
    public static function window(?int $fromHours, ?int $untilHours): TimeWindow
    {
        $at = static fn (?int $hours): ?DateTimeImmutable => $hours === null ? null : new DateTimeImmutable(self::NOW)->modify(sprintf('%+d hours', $hours));

        return new TimeWindow($at($fromHours), $at($untilHours));
    }

    /**
     * A placement of ENTRY, or the entry given, below the node in da with the slug; by default live
     * since an hour ago, canonical, of an active, released ARTICLE.
     */
    public function place(
        string $placement,
        string $node,
        string $slug,
        Visibility $visibility = Visibility::Live,
        ?TimeWindow $window = null,
        bool $canonical = true,
        ?string $type = self::ARTICLE,
        ?EntryLifecycle $lifecycle = EntryLifecycle::Active,
        ?ReleaseState $release = ReleaseState::Released,
        string $entry = self::ENTRY,
    ): self {
        $this->reader->withPlacement(NodeId::fromString($node), new Locale('da'), new Slug($slug), new PlacementMatch(
            PlacementId::fromString($placement),
            EntryId::fromString($entry),
            $visibility,
            $window ?? ($visibility === Visibility::Hidden ? null : self::window(-1, null)),
            $canonical,
            $type === null ? null : TypeId::fromString($type),
            $lifecycle,
            $release,
        ));

        return $this;
    }

    public function resolve(string $host, string $path, string $locale = 'da'): ResolvedPath
    {
        return $this->action()->handle(new ResolvePath(new Host($host), new Locale($locale), new RequestPath($path)));
    }

    public function action(): ResolvePathAction
    {
        return new ResolvePathAction(
            $this->reader,
            new SiteHosts([
                new ConfiguredSite(new SiteHandle('north'), new SiteOrigin('https://north.example'), [new Host('www.north.example')]),
                new ConfiguredSite(new SiteHandle('south'), new SiteOrigin('https://south.example')),
                new ConfiguredSite(new SiteHandle('west'), new SiteOrigin('https://west.example')),
            ]),
            new FakeTypeCatalog(
                $this->type(self::ARTICLE, 'app:article', Stages::DraftRelease, true),
                $this->type(self::READING, 'app:reading', Stages::None, true),
                $this->type(self::NOTE, 'app:note', Stages::DraftRelease, false),
            ),
            $this->clock,
        );
    }

    private function type(string $id, string $name, Stages $stages, bool $routable): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString($id),
            new TypeName($name),
            1,
            new TypeCapabilities($stages === Stages::None ? History::None : History::Full, $stages, Localization::None, $routable),
            [],
            [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\EntryRelease;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The placement commands' reads on Postgres (PRD 5.7, 5.9, 6.2 phase 1), as the app role under the
 * call's actor context, on the default connection, or the one named, and always on the write PDO,
 * inside the command transaction. Each read is one statement.
 *
 * placement() reads the released stage of a placement below a node the actor's regions reach
 * (`cms_access_node`), so a placement another role may see live, but whose node the actor does not
 * reach, reads as absent: placement rights are decided on the placement's node (PRD 5.10). site()
 * takes the root's path from the root's id, the one label of a root's path, because the actor may
 * reach only a section below the root. placements() reads every placement of the entry in the
 * locale through `cms_placement_locales`, which runs as the owner and returns no slug, everyLocale()
 * every placement of the entry through `cms_entry_placement_locales`, which does the same for every
 * locale in the order of the locales, placementVersion() a placement's version through `cms_placement_version`,
 * and entryRelease() the entry's lifecycle and the release state of its shared head through
 * `cms_entry_release`.
 */
#[Internal]
final readonly class PostgresPlacementReader implements PlacementReader
{
    /** The released stage of a placement below a node the actor reaches, a row per locale. */
    public const string PLACEMENT = <<<'SQL'
        select p.entry_id, p.version, g.node_id, pl.locale, pl.slug, pl.visibility, pl.live_from, pl.live_until, pl.canonical
        from placements as p
        join placement_generations as g on g.placement_id = p.id and g.stage = 'released'
        left join placement_locales as pl on pl.placement_id = p.id and pl.stage = 'released'
        where p.id = ?::uuid and cms_access_node(g.node_id)
        order by pl.locale
        SQL;

    /** A node's version, kind and path. */
    public const string NODE = 'select version, kind, path::text as path from nodes where id = ?::uuid';

    /** The kind of a node that shows another node's placements (PRD 5.8). */
    public const string MOUNT = 'mount';

    /** A site with its root and its locales, in the order of the locales. */
    public const string SITE = <<<'SQL'
        select s.version, s.root_node_id, (
            select coalesce(array_to_json(array_agg(l.locale order by l.locale)), '[]'::json)::text
            from site_locales as l where l.site_id = s.id
        ) as locales
        from sites as s
        where s.id = ?::uuid
        SQL;

    /** Whether a placement that is not withdrawn has the slug below the node in the locale. */
    public const string SLUG_TAKEN = <<<'SQL'
        select exists (
            select 1 from placement_locales
            where node_id = ?::uuid and locale = ? and slug = ? and stage = 'released' and visibility <> 'withdrawn'
        ) as taken
        SQL;

    /** An entry's type and lifecycle and its shared head's release state and version, past the actor's regions. */
    public const string ENTRY_RELEASE = 'select type_id::text as type_id, lifecycle, release_state, version from cms_entry_release(?::uuid)';

    /** A placement's version, past the actor's regions. */
    public const string VERSION = 'select cms_placement_version(?::uuid) as version';

    /** Every placement of the entry in the locale, past the actor's regions. */
    public const string PLACEMENTS = 'select placement_id, version, visibility, live_from, live_until, canonical from cms_placement_locales(?::uuid, ?)';

    /** Every placement of the entry in every locale, past the actor's regions. */
    public const string EVERY_LOCALE = 'select locale, placement_id, version, visibility, live_from, live_until, canonical from cms_entry_placement_locales(?::uuid)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function placement(PlacementId $placement): ?StoredPlacement
    {
        $rows = $this->db()->select(self::PLACEMENT, [$placement->toString()], false);

        if ($rows === []) {
            return null;
        }

        $first = PlacementRows::object($rows[0]);
        $locales = [];

        foreach ($rows as $row) {
            $row = PlacementRows::object($row);

            if (PlacementRows::textOrNull($row, 'locale') !== null) {
                $locales[] = new StoredPlacementLocale(
                    new Locale(PlacementRows::text($row, 'locale')),
                    new Slug(PlacementRows::text($row, 'slug')),
                    PlacementRows::visibility($row),
                    PlacementRows::window($row),
                    PlacementRows::boolean($row, 'canonical'),
                );
            }
        }

        return new StoredPlacement(
            $placement,
            EntryId::fromString(PlacementRows::text($first, 'entry_id')),
            NodeId::fromString(PlacementRows::text($first, 'node_id')),
            new AggregateVersion(PlacementRows::integer($first, 'version')),
            $locales,
        );
    }

    #[Override]
    public function placementVersion(PlacementId $placement): ?AggregateVersion
    {
        $version = $this->db()->scalar(self::VERSION, [$placement->toString()], false);

        return $version === null ? null : new AggregateVersion(PlacementRows::integerValue($version, 'the version of a placement'));
    }

    #[Override]
    public function entry(EntryId $entry): ?AggregateVersion
    {
        $version = $this->db()->table('entries')->where('id', $entry->toString())->useWritePdo()->value('version');

        return $version === null ? null : new AggregateVersion(PlacementRows::integerValue($version, 'the version of an entry'));
    }

    #[Override]
    public function node(NodeId $node): ?StoredNode
    {
        $row = $this->db()->selectOne(self::NODE, [$node->toString()], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);

        return new StoredNode(
            $node,
            new AggregateVersion(PlacementRows::integer($row, 'version')),
            new NodePath(PlacementRows::text($row, 'path')),
            PlacementRows::text($row, 'kind') === self::MOUNT,
        );
    }

    #[Override]
    public function site(SiteId $site): ?StoredSite
    {
        $row = $this->db()->selectOne(self::SITE, [$site->toString()], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);

        return new StoredSite(
            $site,
            new AggregateVersion(PlacementRows::integer($row, 'version')),
            new NodePath(str_replace('-', '', PlacementRows::text($row, 'root_node_id'))),
            array_map(static fn (string $locale): Locale => new Locale($locale), TextListColumn::of($row, 'locales')),
        );
    }

    #[Override]
    public function slugTaken(NodeId $node, Locale $locale, Slug $slug): bool
    {
        $row = $this->db()->selectOne(self::SLUG_TAKEN, [$node->toString(), $locale->value, $slug->value], false);

        return PlacementRows::boolean(PlacementRows::object($row), 'taken');
    }

    #[Override]
    public function entryRelease(EntryId $entry): ?EntryRelease
    {
        $row = $this->db()->selectOne(self::ENTRY_RELEASE, [$entry->toString()], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $lifecycle = PlacementRows::text($row, 'lifecycle');
        $release = PlacementRows::textOrNull($row, 'release_state');
        $version = property_exists($row, 'version') ? $row->version : null;

        return new EntryRelease(
            $entry,
            TypeId::fromString(PlacementRows::text($row, 'type_id')),
            EntryLifecycle::tryFrom($lifecycle) ?? throw new UnexpectedValueException(sprintf('The lifecycle "%s" is not a state of PRD 6.4.', $lifecycle)),
            $release === null ? null : (ReleaseState::tryFrom($release) ?? throw new UnexpectedValueException(sprintf('The release state "%s" is not a state of PRD 6.4.', $release))),
            $version === null ? null : new AggregateVersion(PlacementRows::integerValue($version, 'the version of a variant head')),
        );
    }

    #[Override]
    public function placements(EntryId $entry, Locale $locale): LocalePlacements
    {
        $states = [];

        foreach ($this->db()->select(self::PLACEMENTS, [$entry->toString(), $locale->value], false) as $row) {
            $states[] = $this->state(PlacementRows::object($row));
        }

        return new LocalePlacements($entry, $locale, $states);
    }

    #[Override]
    public function everyLocale(EntryId $entry): array
    {
        $byLocale = [];

        foreach ($this->db()->select(self::EVERY_LOCALE, [$entry->toString()], false) as $row) {
            $row = PlacementRows::object($row);
            $byLocale[PlacementRows::text($row, 'locale')][] = $this->state($row);
        }

        $placements = [];

        foreach ($byLocale as $locale => $states) {
            $placements[] = new LocalePlacements($entry, new Locale($locale), $states);
        }

        return $placements;
    }

    private function state(object $row): PlacementState
    {
        return new PlacementState(
            PlacementId::fromString(PlacementRows::text($row, 'placement_id')),
            new AggregateVersion(PlacementRows::integer($row, 'version')),
            PlacementRows::visibility($row),
            PlacementRows::window($row),
            PlacementRows::boolean($row, 'canonical'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

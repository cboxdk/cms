<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Adapter\PlacementRows;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\OwnPlacement;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ReleasedRow;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteRedirect;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use JsonException;
use Override;
use UnexpectedValueException;

/**
 * The reads of path.resolve on Postgres (PRD 5.9), as the app role under the read's actor context,
 * on the default connection, or the one named, and on the write PDO, inside the read transaction.
 * Each read is one statement, so a resolution costs the same queries at any size (GUARDRAILS 4.1).
 * Row level security decides what each returns (PRD 5.10): every context reads the sites, their
 * locales and the routes, and through `cms_routed_node` the kind, the mount source and the tree's
 * root of a node that has a route; the placements, entries and heads a context reads are the public
 * ones, live and released, and for an actor also those its regions reach.
 *
 * - ROUTE: the site by its handle, whether it publishes in the locale, and the longest route of the
 *   site in the locale among the prefixes of the path, looked up in the routes' primary key, with
 *   the node's kind and a mount's source.
 * - PLACEMENT: the released stage of the placement with the slug below the node in the locale, the
 *   one that is not withdrawn first (the partial unique index of invariant 15), else the placement
 *   that had the slug before it was renamed (`slug_history`, PRD 5.9), with its current slug, else
 *   a withdrawn one (`placement_locales_withdrawn_slug`), with the entry's type and lifecycle and
 *   the release state of its shared variant's head, which every entry has, whatever its type's
 *   localization (PRD 10.1), and whether the mount given, if any, hides the entry
 *   (`mount_overrides`, which every context reads). Every context reads the slug history and the
 *   withdrawn rows (`placement_locales_withdrawn`), so the public gets its 301 and its 410.
 * - ROUTE_HISTORY: the longest route a node had before in the locale among the prefixes of the
 *   path (`node_route_history`, which every context reads), with the node's kind and a mount's
 *   source through `cms_routed_node` and the node's current route on the site in the locale.
 * - CANONICAL: the canonical placement of the entry in the locale, its node's route in the site
 *   whose root is the root of the node's tree, and that site's handle; both null when the node has
 *   no route.
 * - OWN: the site's own placement of the entry in the locale (PRD 5.8), the released stage that is
 *   not withdrawn below a node with a route of the site in the locale, the canonical one first and
 *   then the lowest id, with the route, the slug, the stored visibility and the window; a route of
 *   the site belongs to a node of its tree, and a mount holds no placement.
 * - released(): the entry's released rows in its type's table, the shared variant's or, for a
 *   type with localization variants, those of the locales given, by the table's primary key, in
 *   one statement through the query builder, decoded by TypeTableColumns. The anonymous context
 *   reads them only while the entry has a live placement (`<table>_released`), in any locale.
 */
#[Internal]
final readonly class PostgresRouteReader implements RouteReader
{
    public const string ROUTE = <<<'SQL'
        select s.id::text as site_id,
               exists (select 1 from site_locales as l where l.site_id = s.id and l.locale = ? and l.retired_at is null) as published,
               r.route, r.node_id::text as node_id, n.kind, n.mount_source_id::text as mount_source_id
        from sites as s
        left join lateral (
            select nr.route, nr.node_id
            from node_routes as nr
            where nr.site_id = s.id and nr.locale = ? and nr.route in (select json_array_elements_text(?::json))
            order by length(nr.route) desc
            limit 1
        ) as r on true
        left join lateral cms_routed_node(r.node_id) as n on true
        where s.handle = ?
        SQL;

    public const string PLACEMENT = <<<'SQL'
        select found.* from (
            select pl.placement_id::text as placement_id, pl.entry_id::text as entry_id, pl.visibility, pl.live_from, pl.live_until,
                   pl.canonical, e.type_id::text as type_id, e.lifecycle, h.release_state, 0 as rank, null::text as renamed_to,
                   exists (select 1 from mount_overrides as o where o.mount_node_id = ?::uuid and o.entry_id = pl.entry_id and o.hidden) as hidden_on_mount
            from placement_locales as pl
            left join entries as e on e.id = pl.entry_id
            left join variant_heads as h on h.entry_id = pl.entry_id and h.variant = ?
            where pl.node_id = ?::uuid and pl.locale = ? and pl.slug = ? and pl.stage = 'released' and pl.visibility <> 'withdrawn'
            union all
            select pl.placement_id::text, pl.entry_id::text, pl.visibility, pl.live_from, pl.live_until,
                   pl.canonical, e.type_id::text, e.lifecycle, h.release_state, 1, pl.slug,
                   exists (select 1 from mount_overrides as o where o.mount_node_id = ?::uuid and o.entry_id = pl.entry_id and o.hidden)
            from slug_history as sh
            join placement_locales as pl on pl.placement_id = sh.placement_id and pl.locale = sh.locale and pl.stage = 'released'
            left join entries as e on e.id = pl.entry_id
            left join variant_heads as h on h.entry_id = pl.entry_id and h.variant = ?
            where sh.node_id = ?::uuid and sh.locale = ? and sh.slug = ?
            union all
            select pl.placement_id::text, pl.entry_id::text, pl.visibility, pl.live_from, pl.live_until,
                   pl.canonical, e.type_id::text, e.lifecycle, h.release_state, 2, null::text,
                   exists (select 1 from mount_overrides as o where o.mount_node_id = ?::uuid and o.entry_id = pl.entry_id and o.hidden)
            from placement_locales as pl
            left join entries as e on e.id = pl.entry_id
            left join variant_heads as h on h.entry_id = pl.entry_id and h.variant = ?
            where pl.node_id = ?::uuid and pl.locale = ? and pl.slug = ? and pl.stage = 'released' and pl.visibility = 'withdrawn'
        ) as found
        order by found.rank, found.placement_id
        limit 1
        SQL;

    public const string ROUTE_HISTORY = <<<'SQL'
        select h.route, h.node_id::text as node_id, n.kind, n.mount_source_id::text as mount_source_id, r.route as current_route
        from node_route_history as h
        left join lateral cms_routed_node(h.node_id) as n on true
        left join node_routes as r on r.node_id = h.node_id and r.site_id = h.site_id and r.locale = h.locale
        where h.site_id = ?::uuid and h.locale = ? and h.route in (select json_array_elements_text(?::json))
        order by length(h.route) desc
        limit 1
        SQL;

    public const string CANONICAL = <<<'SQL'
        select pl.placement_id::text as placement_id, pl.node_id::text as node_id, pl.slug, s.handle, r.route
        from placement_locales as pl
        left join lateral cms_routed_node(pl.node_id) as n on true
        left join sites as s on s.root_node_id = n.root_id
        left join node_routes as r on r.node_id = pl.node_id and r.site_id = s.id and r.locale = pl.locale
        where pl.entry_id = ?::uuid and pl.locale = ? and pl.stage = 'released' and pl.canonical
        limit 1
        SQL;

    public const string OWN = <<<'SQL'
        select pl.placement_id::text as placement_id, pl.node_id::text as node_id, r.route, pl.slug, pl.visibility, pl.live_from, pl.live_until
        from placement_locales as pl
        join node_routes as r on r.node_id = pl.node_id and r.site_id = ?::uuid and r.locale = pl.locale
        where pl.entry_id = ?::uuid and pl.locale = ? and pl.stage = 'released' and pl.visibility <> 'withdrawn'
        order by pl.canonical desc, pl.placement_id
        limit 1
        SQL;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function route(SiteHandle $site, Locale $locale, RequestPath $path): ?SiteRoute
    {
        $row = $this->db()->selectOne(self::ROUTE, [$locale->value, $locale->value, $this->prefixes($path), $site->value], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $node = PlacementRows::textOrNull($row, 'node_id');
        $kind = PlacementRows::textOrNull($row, 'kind');
        $source = PlacementRows::textOrNull($row, 'mount_source_id');

        return new SiteRoute(
            SiteId::fromString(PlacementRows::text($row, 'site_id')),
            PlacementRows::boolean($row, 'published'),
            $node === null || $kind === null ? null : new RouteMatch(
                PlacementRows::text($row, 'route'),
                NodeId::fromString($node),
                NodeKind::tryFrom($kind) ?? throw new UnexpectedValueException(sprintf('The node kind "%s" is not a kind of PRD 5.8.', $kind)),
                $source === null ? null : NodeId::fromString($source),
            ),
        );
    }

    #[Override]
    public function placement(NodeId $node, Locale $locale, Slug $slug, ?NodeId $mount = null): ?PlacementMatch
    {
        $variant = VariantKey::shared()->value;
        $lookup = [$mount?->toString(), $variant, $node->toString(), $locale->value, $slug->value];
        $row = $this->db()->selectOne(self::PLACEMENT, [...$lookup, ...$lookup, ...$lookup], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $type = PlacementRows::textOrNull($row, 'type_id');
        $lifecycle = PlacementRows::textOrNull($row, 'lifecycle');
        $release = PlacementRows::textOrNull($row, 'release_state');
        $renamedTo = PlacementRows::textOrNull($row, 'renamed_to');

        return new PlacementMatch(
            PlacementId::fromString(PlacementRows::text($row, 'placement_id')),
            EntryId::fromString(PlacementRows::text($row, 'entry_id')),
            PlacementRows::visibility($row),
            PlacementRows::window($row),
            PlacementRows::boolean($row, 'canonical'),
            $type === null ? null : TypeId::fromString($type),
            $lifecycle === null ? null : (EntryLifecycle::tryFrom($lifecycle) ?? throw new UnexpectedValueException(sprintf('The lifecycle "%s" is not a state of PRD 6.4.', $lifecycle))),
            $release === null ? null : (ReleaseState::tryFrom($release) ?? throw new UnexpectedValueException(sprintf('The release state "%s" is not a state of PRD 6.4.', $release))),
            PlacementRows::boolean($row, 'hidden_on_mount'),
            $renamedTo === null ? null : new Slug($renamedTo),
        );
    }

    #[Override]
    public function redirectedRoute(SiteId $site, Locale $locale, RequestPath $path): ?RouteRedirect
    {
        $row = $this->db()->selectOne(self::ROUTE_HISTORY, [$site->toString(), $locale->value, $this->prefixes($path)], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $kind = PlacementRows::textOrNull($row, 'kind');
        $source = PlacementRows::textOrNull($row, 'mount_source_id');

        return new RouteRedirect(
            PlacementRows::text($row, 'route'),
            NodeId::fromString(PlacementRows::text($row, 'node_id')),
            $kind === null ? NodeKind::Section : (NodeKind::tryFrom($kind) ?? throw new UnexpectedValueException(sprintf('The node kind "%s" is not a kind of PRD 5.8.', $kind))),
            $source === null ? null : NodeId::fromString($source),
            PlacementRows::textOrNull($row, 'current_route'),
        );
    }

    #[Override]
    public function canonical(EntryId $entry, Locale $locale): ?CanonicalMatch
    {
        $row = $this->db()->selectOne(self::CANONICAL, [$entry->toString(), $locale->value], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $handle = PlacementRows::textOrNull($row, 'handle');

        return new CanonicalMatch(
            PlacementId::fromString(PlacementRows::text($row, 'placement_id')),
            NodeId::fromString(PlacementRows::text($row, 'node_id')),
            new Slug(PlacementRows::text($row, 'slug')),
            $handle === null ? null : new SiteHandle($handle),
            PlacementRows::textOrNull($row, 'route'),
        );
    }

    #[Override]
    public function own(SiteId $site, EntryId $entry, Locale $locale): ?OwnPlacement
    {
        $row = $this->db()->selectOne(self::OWN, [$site->toString(), $entry->toString(), $locale->value], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);

        return new OwnPlacement(
            PlacementId::fromString(PlacementRows::text($row, 'placement_id')),
            NodeId::fromString(PlacementRows::text($row, 'node_id')),
            PlacementRows::text($row, 'route'),
            new Slug(PlacementRows::text($row, 'slug')),
            PlacementRows::visibility($row),
            PlacementRows::window($row),
        );
    }

    #[Override]
    public function released(TypeDefinition $type, EntryId $entry, array $locales): array
    {
        $variants = $type->capabilities->localization === Localization::Variants
            ? array_map(static fn (Locale $locale): string => VariantKey::of($locale)->value, $locales)
            : [VariantKey::shared()->value];
        $rows = $this->db()->table($type->name->table())
            ->useWritePdo()
            ->where('cms_entry_id', '=', $entry->toString())
            ->whereIn('cms_locale', $variants)
            ->where('cms_stage', '=', 'released')
            ->get();
        $released = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $variant = $row['cms_locale'] ?? null;

            if (! is_string($variant)) {
                throw new UnexpectedValueException(sprintf('A row of %s has no variant in cms_locale.', $type->name->table()));
            }

            $released[] = new ReleasedRow(VariantKey::fromString($variant), TypeTableColumns::decode($type, $row));
        }

        return $released;
    }

    /**
     * The prefixes of the path as a JSON array, for the lookups of a route among them.
     */
    private function prefixes(RequestPath $path): string
    {
        try {
            return json_encode($path->prefixes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The prefixes of a request path are valid UTF-8.', $exception->getCode(), previous: $exception);
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

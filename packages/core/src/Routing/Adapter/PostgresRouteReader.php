<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Placements\Adapter\PlacementRows;
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
 *   one that is not withdrawn first (the partial unique index of invariant 15), else a withdrawn
 *   one (`placement_locales_withdrawn_slug`), with the entry's type and lifecycle and the release
 *   state of its shared variant's head. The first edition of the blueprint schema has no localized
 *   types, so every entry has the one variant `shared`.
 * - CANONICAL: the canonical placement of the entry in the locale, its node's route in the site
 *   whose root is the root of the node's tree, and that site's handle; both null when the node has
 *   no route.
 */
#[Internal]
final readonly class PostgresRouteReader implements RouteReader
{
    public const string ROUTE = <<<'SQL'
        select s.id::text as site_id,
               exists (select 1 from site_locales as l where l.site_id = s.id and l.locale = ?) as published,
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
                   pl.canonical, e.type_id::text as type_id, e.lifecycle, h.release_state, false as withdrawn
            from placement_locales as pl
            left join entries as e on e.id = pl.entry_id
            left join variant_heads as h on h.entry_id = pl.entry_id and h.variant = ?
            where pl.node_id = ?::uuid and pl.locale = ? and pl.slug = ? and pl.stage = 'released' and pl.visibility <> 'withdrawn'
            union all
            select pl.placement_id::text, pl.entry_id::text, pl.visibility, pl.live_from, pl.live_until,
                   pl.canonical, e.type_id::text, e.lifecycle, h.release_state, true
            from placement_locales as pl
            left join entries as e on e.id = pl.entry_id
            left join variant_heads as h on h.entry_id = pl.entry_id and h.variant = ?
            where pl.node_id = ?::uuid and pl.locale = ? and pl.slug = ? and pl.stage = 'released' and pl.visibility = 'withdrawn'
        ) as found
        order by found.withdrawn, found.placement_id
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
        try {
            $prefixes = json_encode($path->prefixes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The prefixes of a request path are valid UTF-8.', $exception->getCode(), previous: $exception);
        }

        $row = $this->db()->selectOne(self::ROUTE, [$locale->value, $locale->value, $prefixes, $site->value], false);

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
    public function placement(NodeId $node, Locale $locale, Slug $slug): ?PlacementMatch
    {
        $variant = VariantKey::shared()->value;
        $lookup = [$variant, $node->toString(), $locale->value, $slug->value];
        $row = $this->db()->selectOne(self::PLACEMENT, [...$lookup, ...$lookup], false);

        if ($row === null) {
            return null;
        }

        $row = PlacementRows::object($row);
        $type = PlacementRows::textOrNull($row, 'type_id');
        $lifecycle = PlacementRows::textOrNull($row, 'lifecycle');
        $release = PlacementRows::textOrNull($row, 'release_state');

        return new PlacementMatch(
            PlacementId::fromString(PlacementRows::text($row, 'placement_id')),
            EntryId::fromString(PlacementRows::text($row, 'entry_id')),
            PlacementRows::visibility($row),
            PlacementRows::window($row),
            PlacementRows::boolean($row, 'canonical'),
            $type === null ? null : TypeId::fromString($type),
            $lifecycle === null ? null : (EntryLifecycle::tryFrom($lifecycle) ?? throw new UnexpectedValueException(sprintf('The lifecycle "%s" is not a state of PRD 6.4.', $lifecycle))),
            $release === null ? null : (ReleaseState::tryFrom($release) ?? throw new UnexpectedValueException(sprintf('The release state "%s" is not a state of PRD 6.4.', $release))),
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

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

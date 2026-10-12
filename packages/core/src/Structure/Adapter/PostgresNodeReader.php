<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\MountedEntry;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredMountOverride;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The NodeReader on Postgres: one statement per read, through the owner lookups `cms_structure_node`,
 * `cms_structure_route_holder`, `cms_structure_node_route` and `cms_structure_node_placement` (see
 * the migration that adds the node commands) and `cms_structure_ancestors` and
 * `cms_structure_routed_node` (the migration that adds node.move) and `cms_structure_mounted_entry`
 * (the migration that adds the mount commands), which read past the actor's regions and raise
 * 42501 without an actor context; a mount's override is read through the query builder, because
 * every context reads `mount_overrides`. It reads on the write PDO, inside the command transaction,
 * on the default connection or the one named.
 */
#[Internal]
final readonly class PostgresNodeReader implements NodeReader
{
    public const string NODE = 'select parent_id::text as parent_id, kind, path, lifecycle, version, reachable, mount_source_id::text as mount_source_id from cms_structure_node(?::uuid)';

    public const string ANCESTORS = 'select id::text as id, parent_id::text as parent_id, kind, path, lifecycle, version, reachable from cms_structure_ancestors(?::uuid)';

    public const string ROUTED_BELOW = 'select cms_structure_routed_node(?::uuid)::text as node_id';

    public const string ROUTE_HOLDER = 'select cms_structure_route_holder(?::uuid, ?, ?)::text as node_id';

    public const string NODE_ROUTE = 'select cms_structure_node_route(?::uuid, ?, ?::uuid) as route';

    public const string VISIBLE_PLACEMENT = 'select cms_structure_node_placement(?::uuid, ?::timestamptz)::text as placement_id';

    public const string MOUNTED_ENTRY = 'select found, placement_id::text as placement_id from cms_structure_mounted_entry(?::uuid, ?::uuid, ?::timestamptz)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function node(NodeId $node): ?StoredNode
    {
        $row = $this->db()->selectOne(self::NODE, [$node->toString()], false);

        if ($row === null) {
            return null;
        }

        return $this->stored($node, NodeRows::object($row));
    }

    #[Override]
    public function ancestors(NodeId $node): array
    {
        $ancestors = [];

        foreach ($this->db()->select(self::ANCESTORS, [$node->toString()], false) as $row) {
            $row = NodeRows::object($row);
            $ancestors[] = $this->stored(NodeId::fromString(NodeRows::text($row, 'id')), $row);
        }

        return $ancestors;
    }

    #[Override]
    public function routedBelow(NodeId $node): ?NodeId
    {
        $routed = $this->value(self::ROUTED_BELOW, [$node->toString()], 'node_id');

        return $routed === null ? null : NodeId::fromString($routed);
    }

    #[Override]
    public function routeHolder(SiteId $site, Locale $locale, RequestPath $route): ?NodeId
    {
        $holder = $this->value(self::ROUTE_HOLDER, [$site->toString(), $locale->value, $route->value], 'node_id');

        return $holder === null ? null : NodeId::fromString($holder);
    }

    #[Override]
    public function routeOf(SiteId $site, Locale $locale, NodeId $node): ?RequestPath
    {
        $route = $this->value(self::NODE_ROUTE, [$site->toString(), $locale->value, $node->toString()], 'route');

        return $route === null ? null : new RequestPath($route);
    }

    #[Override]
    public function visiblePlacement(NodeId $node, DateTimeImmutable $at): ?PlacementId
    {
        $placement = $this->value(self::VISIBLE_PLACEMENT, [$node->toString(), Timestamps::of($at)], 'placement_id');

        return $placement === null ? null : PlacementId::fromString($placement);
    }

    #[Override]
    public function mountOverride(NodeId $mount, EntryId $entry): ?StoredMountOverride
    {
        $row = $this->db()->table('mount_overrides')
            ->useWritePdo()
            ->where('mount_node_id', '=', $mount->toString())
            ->where('entry_id', '=', $entry->toString())
            ->first(['hidden', 'version']);

        if ($row === null) {
            return null;
        }

        $row = NodeRows::object($row);

        return new StoredMountOverride($mount, $entry, NodeRows::boolean($row, 'hidden'), new AggregateVersion(NodeRows::integer($row, 'version')));
    }

    #[Override]
    public function mountedEntry(NodeId $node, EntryId $entry, DateTimeImmutable $at): ?MountedEntry
    {
        $row = $this->db()->selectOne(self::MOUNTED_ENTRY, [$node->toString(), $entry->toString(), Timestamps::of($at)], false);

        if ($row === null) {
            throw new UnexpectedValueException('The lookup of a mounted entry answered no row; it always answers one.');
        }

        $row = NodeRows::object($row);

        if (! NodeRows::boolean($row, 'found')) {
            return null;
        }

        $placement = NodeRows::textOrNull($row, 'placement_id');

        return new MountedEntry($entry, $placement === null ? null : PlacementId::fromString($placement));
    }

    /**
     * The node a row of a node lookup describes; the ancestors lookup gives no mount source, because
     * a mount holds no node below it.
     */
    private function stored(NodeId $node, object $row): StoredNode
    {
        $parent = NodeRows::textOrNull($row, 'parent_id');
        $source = NodeRows::textOrNull($row, 'mount_source_id');

        return new StoredNode(
            $node,
            $parent === null ? null : NodeId::fromString($parent),
            NodeKind::from(NodeRows::text($row, 'kind')),
            new NodePath(NodeRows::text($row, 'path')),
            NodeLifecycle::from(NodeRows::text($row, 'lifecycle')),
            new AggregateVersion(NodeRows::integer($row, 'version')),
            NodeRows::boolean($row, 'reachable'),
            $source === null ? null : NodeId::fromString($source),
        );
    }

    /**
     * @param  list<string>  $bindings
     */
    private function value(string $query, array $bindings, string $column): ?string
    {
        $row = $this->db()->selectOne($query, $bindings, false);

        if ($row === null) {
            throw new UnexpectedValueException(sprintf('The lookup of %s answered no row; a scalar lookup always answers one.', $column));
        }

        return NodeRows::textOrNull(NodeRows::object($row), $column);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

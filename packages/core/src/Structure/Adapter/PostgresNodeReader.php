<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The NodeReader on Postgres: one row per read, through the owner lookups `cms_structure_node`,
 * `cms_structure_route_holder`, `cms_structure_node_route` and `cms_structure_node_placement` (see
 * the migration that adds the node commands), which read past the actor's regions and raise 42501
 * without an actor context. It reads on the write PDO, inside the command transaction, on the
 * default connection or the one named.
 */
#[Internal]
final readonly class PostgresNodeReader implements NodeReader
{
    public const string NODE = 'select parent_id::text as parent_id, kind, path, lifecycle, version, reachable from cms_structure_node(?::uuid)';

    public const string ROUTE_HOLDER = 'select cms_structure_route_holder(?::uuid, ?, ?)::text as node_id';

    public const string NODE_ROUTE = 'select cms_structure_node_route(?::uuid, ?, ?::uuid) as route';

    public const string VISIBLE_PLACEMENT = 'select cms_structure_node_placement(?::uuid, ?::timestamptz)::text as placement_id';

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

        $row = NodeRows::object($row);
        $parent = NodeRows::textOrNull($row, 'parent_id');

        return new StoredNode(
            $node,
            $parent === null ? null : NodeId::fromString($parent),
            NodeKind::from(NodeRows::text($row, 'kind')),
            new NodePath(NodeRows::text($row, 'path')),
            NodeLifecycle::from(NodeRows::text($row, 'lifecycle')),
            new AggregateVersion(NodeRows::integer($row, 'version')),
            NodeRows::boolean($row, 'reachable'),
        );
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

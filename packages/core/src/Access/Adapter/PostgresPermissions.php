<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Boundary\GrantRows;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use UnexpectedValueException;

/**
 * The reads the authorizers decide a permission from (PRD 5.10), on the default connection, or the
 * one named, inside the caller's transaction and under the actor context it set, through the query
 * builder on the write PDO: the actor's grants that have not ended of the roles whose
 * role_permissions name the command or query, with the role's ceiling and the node's path, and the
 * paths of nodes. Row level security limits both: the app role reads the grants of the context's
 * actor, the nodes they name and the nodes its regions reach, so a node the actor does not reach
 * has no path here.
 */
#[Internal]
final readonly class PostgresPermissions
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * @return list<Grant>
     */
    public function grants(ActorId $actor, CommandName $permission): array
    {
        $grants = [];

        foreach ($this->db()->table('grants as g')
            ->useWritePdo()
            ->join('role_permissions as p', 'p.role_id', '=', 'g.role_id')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('nodes as n', 'n.id', '=', 'g.node_id')
            ->where('g.actor_id', $actor->toString())
            ->where('p.command', $permission->value)
            ->whereNull('g.ended_changeset_id')
            ->orderBy('g.id')
            ->get(GrantRows::COLUMNS) as $row) {
            $grants[] = GrantRows::grant($row);
        }

        return $grants;
    }

    /**
     * The path of each node the caller's context reads, keyed by the node's id.
     *
     * @param  list<NodeId>  $nodes
     * @return array<string, NodePath>
     */
    public function paths(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $paths = [];

        foreach ($this->db()->table('nodes')
            ->useWritePdo()
            ->whereIn('id', array_values(array_unique(array_map(static fn (NodeId $node): string => $node->toString(), $nodes))))
            ->get(['id', 'path']) as $row) {
            if (! is_string($row->id ?? null) || ! is_string($row->path ?? null)) {
                throw new UnexpectedValueException('A node row has the text columns id and path.');
            }

            $paths[$row->id] = new NodePath($row->path);
        }

        return $paths;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

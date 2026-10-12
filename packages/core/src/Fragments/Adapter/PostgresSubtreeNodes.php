<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The SubtreeNodes on Postgres: one statement per page, through the owner lookup
 * `cms_structure_subtree` (see the migration that adds node.move), which reads past the actor's
 * regions and raises 42501 without an actor context. It reads on the write PDO, inside the
 * runner's batch transaction, on the default connection or the one named.
 */
#[Internal]
final readonly class PostgresSubtreeNodes implements SubtreeNodes
{
    public const string BELOW = 'select cms_structure_subtree::text as id from cms_structure_subtree(?::uuid, ?::uuid, ?::integer)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function below(NodeId $node, ?NodeId $after, int $limit): array
    {
        $ids = [];

        foreach ($this->connections->connection($this->connection)->select(self::BELOW, [$node->toString(), $after?->toString(), $limit], false) as $row) {
            $id = is_object($row) && property_exists($row, 'id') ? $row->id : null;

            if (! is_string($id)) {
                throw new UnexpectedValueException(sprintf('A node of a subtree has a text id, got %s.', get_debug_type($id)));
            }

            $ids[] = NodeId::fromString($id);
        }

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\BatchVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the node aggregate (PRD 5.8, 6.2 phase 7): the node's row in `nodes`, FOR
 * SHARE for Share and FOR NO KEY UPDATE for Update, so an entry created below a node waits for a
 * change of the node, such as a move, and is checked against the node's version after it. It runs
 * on the default connection, or the one named, inside the command transaction.
 *
 * It locks through the owner lookup `cms_structure_lock_nodes` (see the migration that adds the
 * mount commands), which reads past the actor's regions and raises 42501 without an actor context,
 * because a command reads nodes past the regions too: the source of a mount, the ancestors of a
 * parent, a node it refuses as unauthorized. As the app role, `nodes_actor` would hide such a node
 * at commit and the command would be version_conflict against a node that is there. The lookup
 * takes a row lock and gives a version, nothing more; what a command may change is decided before
 * the commit, and the commit refuses a plan that changes an aggregate the action did not read.
 */
#[Internal]
final readonly class PostgresNodeVersionLock implements BatchVersionLock
{
    public const string KIND = 'node';

    /** The lock of the rows of the ids, as the owner role, each with its version. */
    public const string LOCK = 'select id::text as id, version from cms_structure_lock_nodes(?::uuid[], ?::boolean)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function kind(): string
    {
        return self::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof NodeId) {
            throw new InvalidArgumentException(sprintf('The node version lock locks nodes, not "%s".', $aggregate->aggregateKey()));
        }

        return $this->lockAll([$aggregate], $strength)[$aggregate->aggregateKey()] ?? null;
    }

    #[Override]
    public function lockAll(array $aggregates, LockStrength $strength): array
    {
        $versions = [];

        foreach ($aggregates as $aggregate) {
            if (! $aggregate instanceof NodeId) {
                throw new InvalidArgumentException(sprintf('The node version lock locks nodes, not "%s".', $aggregate->aggregateKey()));
            }

            $versions[$aggregate->aggregateKey()] = null;
        }

        $rows = $this->connections->connection($this->connection)->select(
            self::LOCK,
            [
                '{'.implode(',', array_map(static fn (NodeId $node): string => $node->toString(), $aggregates)).'}',
                $strength === LockStrength::Update ? 'true' : 'false',
            ],
            false,
        );

        foreach ($rows as $row) {
            $id = is_object($row) && property_exists($row, 'id') ? $row->id : null;
            $version = is_object($row) && property_exists($row, 'version') ? $row->version : null;

            if (! is_string($id) || ! is_int($version)) {
                throw new UnexpectedValueException(sprintf('A locked node has a text id and an integer version, got %s and %s.', get_debug_type($id), get_debug_type($version)));
            }

            $versions[NodeId::fromString($id)->aggregateKey()] = new AggregateVersion($version);
        }

        return $versions;
    }
}

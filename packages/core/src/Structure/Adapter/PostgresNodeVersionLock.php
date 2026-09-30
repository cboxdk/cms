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
 * The version lock of the node aggregate (PRD 5.8, 6.2 phase 7), as the app role under the call's
 * actor context: the node's row in `nodes`, FOR SHARE for Share and FOR NO KEY UPDATE for Update,
 * so an entry created below a node waits for a change of the node, such as a move, and is checked
 * against the node's version after it. It runs on the default connection, or the one named, inside
 * the command transaction.
 */
#[Internal]
final readonly class PostgresNodeVersionLock implements BatchVersionLock
{
    public const string KIND = 'node';

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

        $version = $this->connections->connection($this->connection)
            ->table('nodes')
            ->where('id', $aggregate->toString())
            ->lock($strength === LockStrength::Update ? 'for no key update' : 'for share')
            ->useWritePdo()
            ->value('version');

        if ($version === null) {
            return null;
        }

        if (! is_int($version)) {
            throw new UnexpectedValueException(sprintf('The version of a node is an integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion($version);
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
            sprintf(
                'select id::text as id, version from nodes where id = any(?::uuid[]) order by id %s',
                $strength === LockStrength::Update ? 'for no key update' : 'for share',
            ),
            ['{'.implode(',', array_map(static fn (NodeId $node): string => $node->toString(), $aggregates)).'}'],
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

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The SeedReader on Postgres: one statement for the entries of a chunk and one for their home
 * nodes, on the caller's connection and under its actor context, so an entry or a node out of the
 * actor's reach reads as absent. Reads use the write PDO, as the other readers of the command
 * transaction do.
 */
#[Internal]
final readonly class PostgresSeedReader implements SeedReader
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function existing(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $found = [];

        foreach ($this->db()->table('entries')->whereIn('id', array_map(static fn (EntryId $entry): string => $entry->toString(), $entries))->useWritePdo()->pluck('id') as $id) {
            $found[is_string($id) ? $id : throw new UnexpectedValueException(sprintf('The id of an entry is text, got %s.', get_debug_type($id)))] = true;
        }

        return array_values(array_filter($entries, static fn (EntryId $entry): bool => isset($found[$entry->toString()])));
    }

    #[Override]
    public function nodes(array $nodes): array
    {
        $versions = [];

        foreach ($nodes as $node) {
            $versions[$node->toString()] = null;
        }

        if ($versions === []) {
            return [];
        }

        $rows = $this->db()->table('nodes')->whereIn('id', array_keys($versions))->useWritePdo()->get(['id', 'version']);

        foreach ($rows as $row) {
            $id = $row->id ?? null;
            $version = $row->version ?? null;

            if (! is_string($id) || ! is_int($version)) {
                throw new UnexpectedValueException(sprintf('A node has a text id and an integer version, got %s and %s.', get_debug_type($id), get_debug_type($version)));
            }

            $versions[NodeId::fromString($id)->toString()] = new AggregateVersion($version);
        }

        return $versions;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

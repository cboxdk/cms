<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryTypes;
use Cbox\Cms\Core\Pipeline\Domain\RelationTargets;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The targets of a plan's relations on Postgres (PRD 5.11): one statement on `entries` by the ids,
 * as the app role under the call's actor context, on the default connection, or the one named,
 * on the write PDO, inside the command transaction. Row level security hides an entry the actor
 * cannot read, so it reads as absent; no read at all is made for no entries.
 */
#[Internal]
final readonly class PostgresRelationTargets implements RelationTargets
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function types(array $entries): EntryTypes
    {
        if ($entries === []) {
            return new EntryTypes;
        }

        $rows = $this->db()
            ->table('entries')
            ->whereIn('id', array_map(static fn (EntryId $entry): string => $entry->toString(), $entries))
            ->select(['id', 'type_id'])
            ->useWritePdo()
            ->get();

        $types = [];

        foreach ($rows as $row) {
            $id = $row->id ?? null;
            $type = $row->type_id ?? null;

            if (! is_string($id) || ! is_string($type)) {
                throw new UnexpectedValueException('The entries table returned a row without its id and type.');
            }

            $types[$id] = TypeId::fromString($type);
        }

        return new EntryTypes($types);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

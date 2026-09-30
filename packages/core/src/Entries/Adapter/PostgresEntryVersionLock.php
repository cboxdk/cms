<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\BatchVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the entry aggregate (PRD 5.4, 6.2 phase 7), as the app role under the call's
 * actor context: the entry's row in `entries`, FOR SHARE for Share and FOR NO KEY UPDATE for Update,
 * so rows that only reference the entry, such as a revision, never wait for it. It runs on the
 * default connection, or the one named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresEntryVersionLock implements BatchVersionLock
{
    public const string KIND = 'entry';

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
        if (! $aggregate instanceof EntryId) {
            throw new InvalidArgumentException(sprintf('The entry version lock locks entries, not "%s".', $aggregate->aggregateKey()));
        }

        return RowVersion::of($this->connections->connection($this->connection)
            ->table('entries')
            ->where('id', $aggregate->toString())
            ->lock(RowVersion::clause($strength))
            ->useWritePdo()
            ->value('version'), $aggregate);
    }

    #[Override]
    public function lockAll(array $aggregates, LockStrength $strength): array
    {
        $versions = [];

        foreach ($aggregates as $aggregate) {
            if (! $aggregate instanceof EntryId) {
                throw new InvalidArgumentException(sprintf('The entry version lock locks entries, not "%s".', $aggregate->aggregateKey()));
            }

            $versions[$aggregate->aggregateKey()] = null;
        }

        $rows = $this->connections->connection($this->connection)->select(
            sprintf('select id::text as id, version from entries where id = any(?::uuid[]) order by id %s', RowVersion::clause($strength)),
            ['{'.implode(',', array_map(static fn (EntryId $entry): string => $entry->toString(), $aggregates)).'}'],
            false,
        );

        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new UnexpectedValueException(sprintf('A locked row is an object, got %s.', get_debug_type($row)));
            }

            $entry = EntryId::fromString(RowVersion::text($row, 'id'));
            $versions[$entry->aggregateKey()] = RowVersion::of($row->version ?? null, $entry);
        }

        return $versions;
    }
}

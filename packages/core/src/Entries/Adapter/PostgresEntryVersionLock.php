<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of the entry aggregate (PRD 5.4, 6.2 phase 7), as the app role under the call's
 * actor context: the entry's row in `entries`, FOR SHARE for Share and FOR NO KEY UPDATE for Update,
 * so rows that only reference the entry, such as a revision, never wait for it. It runs on the
 * default connection, or the one named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresEntryVersionLock implements VersionLock
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
}

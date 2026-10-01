<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Placements\Domain\EntryReleaseRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of whether an entry may be shown (PRD 5.7, invariant 6): the entry's row FOR
 * SHARE and the head of its shared variant FOR SHARE, or FOR NO KEY UPDATE for Update, through
 * `cms_entry_release_lock`, past the actor's regions, because the placement a command makes live
 * may lie in other regions than the entry's home. Its version is the head's while the entry is
 * active, and null otherwise. It runs on the default connection, or the one named, inside the
 * command transaction.
 */
#[Internal]
final readonly class PostgresEntryReleaseLock implements VersionLock
{
    public const string LOCK = 'select cms_entry_release_lock(?::uuid, ?::boolean) as version';

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
        return EntryReleaseRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof EntryReleaseRef) {
            throw new InvalidArgumentException(sprintf('The entry release lock locks the release of an entry, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)->scalar(self::LOCK, [$aggregate->entry->toString(), $strength === LockStrength::Update], false);

        return $version === null ? null : new AggregateVersion(PlacementRows::integerValue($version, 'the version of a variant head'));
    }
}

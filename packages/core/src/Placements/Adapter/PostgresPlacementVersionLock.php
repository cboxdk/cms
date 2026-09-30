<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of the placement aggregate (PRD 5.7, 6.2 phase 7): the placement's row in
 * `placements`, FOR SHARE for Share and FOR NO KEY UPDATE for Update, through `cms_placement_lock`,
 * because a command reads every placement of an entry in a locale for the canonical rule, also
 * those below nodes the actor's regions do not reach (invariant 14). It runs on the default
 * connection, or the one named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresPlacementVersionLock implements VersionLock
{
    public const string KIND = 'placement';

    public const string LOCK = 'select cms_placement_lock(?::uuid, ?::boolean) as version';

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
        if (! $aggregate instanceof PlacementId) {
            throw new InvalidArgumentException(sprintf('The placement version lock locks placements, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)->scalar(self::LOCK, [$aggregate->toString(), $strength === LockStrength::Update], false);

        return $version === null ? null : new AggregateVersion(PlacementRows::integerValue($version, 'the version of a placement'));
    }
}

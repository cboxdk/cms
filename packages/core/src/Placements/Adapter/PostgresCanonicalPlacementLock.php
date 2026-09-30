<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of whether an entry has a canonical placement in a locale (PRD 5.7,
 * invariant 14): version 1 when one of its placements is canonical there, and null when none is,
 * through `cms_placement_canonical`, across every site. A command that found none reads it as
 * absent, so the commit takes the advisory lock of an aggregate read as absent first, and of two
 * commands that would each make a placement canonical the second is version_conflict. When one is
 * canonical, the commands read that placement too, and its row lock and version keep the flag from
 * moving meanwhile.
 */
#[Internal]
final readonly class PostgresCanonicalPlacementLock implements VersionLock
{
    public const string READ = 'select cms_placement_canonical(?::uuid, ?) as canonical';

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
        return CanonicalPlacementRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof CanonicalPlacementRef) {
            throw new InvalidArgumentException(sprintf('The canonical lock locks the canonical placement of an entry, not "%s".', $aggregate->aggregateKey()));
        }

        $row = $this->connections->connection($this->connection)->selectOne(self::READ, [$aggregate->entry->toString(), $aggregate->locale->value], false);

        return PlacementRows::boolean(PlacementRows::object($row), 'canonical') ? AggregateVersion::first() : null;
    }
}

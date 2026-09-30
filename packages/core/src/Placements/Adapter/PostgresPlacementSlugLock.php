<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of a slug below a node in a locale (PRD 5.9, invariant 15): version 1 when a
 * placement that is not withdrawn has the slug there, and null when it is free. A command that
 * gives a placement the slug reads it as free, so the commit takes the advisory lock of an
 * aggregate read as absent first, and two commands that claim the slug commit one after the other;
 * the second finds it taken and is version_conflict. It reads the slug below a node the actor's
 * regions reach, as the command did.
 */
#[Internal]
final readonly class PostgresPlacementSlugLock implements VersionLock
{
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
        return PlacementSlugRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof PlacementSlugRef) {
            throw new InvalidArgumentException(sprintf('The slug lock locks slugs, not "%s".', $aggregate->aggregateKey()));
        }

        $taken = new PostgresPlacementReader($this->connections, $this->connection)->slugTaken($aggregate->node, $aggregate->locale, $aggregate->slug);

        return $taken ? AggregateVersion::first() : null;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Pipeline\Domain\BatchVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Locks actors' sets of grants in the commit (PRD 6.2 phase 7, 5.10, invariant 31) and gives the
 * version of each, through the owner function cms_access_lock_actor_grants: a transaction-scoped
 * advisory lock on each set in actor id order, then the versions read after the locks. The lock is
 * exclusive whatever the strength, because no mutation names a set, so the commit reads every set
 * as Share: the set of an issuer the escalation guard decided from and the set of an actor whose
 * grants the command changes are locked alike, and two commands that touch one set commit one after
 * the other. It runs on the default connection, or the one named, inside the command transaction
 * and under its actor context.
 */
#[Internal]
final readonly class PostgresActorGrantsLock implements BatchVersionLock
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
        return ActorGrantsRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): AggregateVersion
    {
        $versions = $this->lockAll([$aggregate], $strength);

        return $versions[$aggregate->aggregateKey()] ?? throw new UnexpectedValueException(sprintf('The set of grants "%s" has a version.', $aggregate->aggregateKey()));
    }

    #[Override]
    public function lockAll(array $aggregates, LockStrength $strength): array
    {
        $actors = [];

        foreach ($aggregates as $aggregate) {
            if (! $aggregate instanceof ActorGrantsRef) {
                throw new InvalidArgumentException(sprintf('The actor grants lock locks the grants of actors, not "%s".', $aggregate->aggregateKey()));
            }

            $actors[$aggregate->aggregateKey()] = $aggregate->actor;
        }

        $read = ActorGrantVersions::of($this->connections->connection($this->connection), array_values($actors), lock: true);
        $versions = [];

        foreach ($actors as $key => $actor) {
            $versions[$key] = $read[$actor->toString()] ?? throw new UnexpectedValueException(sprintf('The set of grants "%s" has a version.', $key));
        }

        return $versions;
    }
}

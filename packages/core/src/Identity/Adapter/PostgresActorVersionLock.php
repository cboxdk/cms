<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the actor aggregate (PRD 5.16, 6.2 phase 7, invariant 37), as the app role:
 * the commit locks the actor and each actor of its on-behalf-of chain through it, so a change of
 * an actor, such as its deactivation, either commits before the command's version check sees it
 * or waits until the command has committed.
 *
 * The app role reads no identity row itself, so it locks through the lookup function LOCK, which
 * runs as the owner role and returns the actor's version alone (see the migration that adds it),
 * FOR SHARE for Share and FOR NO KEY UPDATE for Update. It runs on the default connection, or the
 * one named, inside the caller's transaction.
 */
#[Internal]
final readonly class PostgresActorVersionLock implements VersionLock
{
    public const string KIND = 'actor';

    /** The lock of one actor's row, as the owner role. */
    public const string LOCK = 'select cms_identity_lock_actor(?::uuid, ?::boolean)::text as version';

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
        if (! $aggregate instanceof ActorId) {
            throw new InvalidArgumentException(sprintf('The actor version lock locks actors, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)->scalar(
            self::LOCK,
            [$aggregate->toString(), $strength === LockStrength::Update ? 'true' : 'false'],
            false,
        );

        if ($version === null) {
            return null;
        }

        if (! is_string($version) || preg_match('/\A[1-9][0-9]*\z/', $version) !== 1) {
            throw new UnexpectedValueException(sprintf('The version of an actor is a positive integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion((int) $version);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Locks one grant's row in the commit (PRD 6.2 phase 7, 5.10) and gives its version, or null when
 * no grant has the id. The app role writes no grant and so may not lock one itself, so the lock is
 * the owner function cms_access_lock_grant: FOR SHARE when the changeset only read the grant, FOR
 * NO KEY UPDATE when it changes it. It runs on the default connection, or the one named, inside
 * the command transaction and under its actor context.
 */
#[Internal]
final readonly class PostgresGrantVersionLock implements VersionLock
{
    public const string KIND = 'grant';

    /** The lock of one grant's row, as the owner role. */
    public const string LOCK = 'select cms_access_lock_grant(?::uuid, ?::boolean)::text as version';

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
        if (! $aggregate instanceof GrantId) {
            throw new InvalidArgumentException(sprintf('The grant version lock locks grants, not "%s".', $aggregate->aggregateKey()));
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
            throw new UnexpectedValueException(sprintf('The version of a grant is a positive integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion((int) $version);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\RoleGrantsRef;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Gives the version of a role's set of grants in the commit (PRD 6.2 phase 7, 5.10): one more than
 * the number of grants ever given of the role, through the owner function
 * cms_access_role_grants_version. It takes no lock of its own: the commit has locked the role
 * before it, because "role:" sorts before "role_grants:", and every grant.assign holds the role FOR
 * SHARE until it commits, so once a change holds the role FOR NO KEY UPDATE no assign of it is in
 * flight and the count is final for the rest of the transaction.
 */
#[Internal]
final readonly class PostgresRoleGrantsLock implements VersionLock
{
    /** The version of a role's set of grants, as the owner role. */
    public const string LOCK = 'select cms_access_role_grants_version(?::uuid) as version';

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
        return RoleGrantsRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): AggregateVersion
    {
        if (! $aggregate instanceof RoleGrantsRef) {
            throw new InvalidArgumentException(sprintf('The role grants lock locks the grants of roles, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)->scalar(self::LOCK, [$aggregate->role->toString()], false);

        if (! is_int($version) || $version < 1) {
            throw new UnexpectedValueException(sprintf('The version of a role\'s grants is a positive integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion($version);
    }
}

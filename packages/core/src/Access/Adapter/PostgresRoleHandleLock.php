<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\RoleHandleRef;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Locks the role with a handle in the commit (PRD 6.2 phase 7, 5.10), FOR SHARE, through the owner
 * function cms_access_lock_role_handle. A handle holds no version of its own, so a handle a role
 * has is version 1 and a free one null. role.create reads the handle as absent, so the commit takes
 * its advisory lock before this, and a second create of the handle that waited for the first finds
 * it taken and ends in version_conflict.
 */
#[Internal]
final readonly class PostgresRoleHandleLock implements VersionLock
{
    /** The lock of the role with a handle, as the owner role. */
    public const string LOCK = 'select cms_access_lock_role_handle(?) as taken';

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
        return RoleHandleRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof RoleHandleRef) {
            throw new InvalidArgumentException(sprintf('The role handle lock locks role handles, not "%s".', $aggregate->aggregateKey()));
        }

        $taken = $this->connections->connection($this->connection)->scalar(self::LOCK, [$aggregate->handle->value], false);

        if (! is_bool($taken)) {
            throw new UnexpectedValueException(sprintf('The lock of a role handle says whether a role has it, got %s.', get_debug_type($taken)));
        }

        return $taken ? AggregateVersion::first() : null;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RolePermissionsSet;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes RolePermissionsSet in the commit (PRD 5.10, 6.2 phase 7): the role moves to the context's
 * version, its rows of role_permissions that the list leaves out go, and a row is added, created at
 * the changeset's time, for each permission it lacked. The grants of the role move in the same
 * plan (GrantRoleContentChangedWriter), which returns their grant.changed, so this returns no
 * event.
 *
 * The app role writes no role itself, so the writer calls SET, which runs as the owner role and
 * only in the transaction of a role.set_permissions changeset by the context's actor (see the
 * migration that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the role and checked its version.
 */
#[Internal]
final readonly class RolePermissionsSetWriter implements MutationWriter
{
    /** The new permissions of one role, as the owner role. */
    public const string SET = 'select cms_access_set_role_permissions(?::uuid, ?::text[], ?::bigint, ?::timestamptz, ?::uuid)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return RolePermissionsSet::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof RolePermissionsSet) {
            throw new InvalidArgumentException(sprintf('The role permissions writer writes RolePermissionsSet, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::SET, [
            $mutation->role->toString(),
            RoleRows::permissions($mutation->permissions),
            $context->version->value,
            Timestamps::of($context->at),
            $context->changesetId->toString(),
        ]);

        return [];
    }
}

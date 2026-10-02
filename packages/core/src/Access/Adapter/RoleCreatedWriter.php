<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RoleCreated;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes RoleCreated in the commit (PRD 5.10, 6.2 phase 7): the role with its handle and ceiling at
 * the context's version, and a row of role_permissions per permission, created at the changeset's
 * time. The role is granted to nobody yet, so no actor's access changes and it returns no event.
 *
 * The app role writes no role itself, so the writer calls CREATE, which runs as the owner role and
 * only in the transaction of a role.create changeset by the context's actor (see the migration that
 * adds it). It runs on the default connection, or the one named, inside the command transaction,
 * after the commit has locked the role's id and handle.
 */
#[Internal]
final readonly class RoleCreatedWriter implements MutationWriter
{
    /** The creation of one role with its permissions, as the owner role. */
    public const string CREATE = 'select cms_access_create_role(?::uuid, ?, ?, ?::text[], ?::bigint, ?::timestamptz, ?::uuid)';

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
        return RoleCreated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof RoleCreated) {
            throw new InvalidArgumentException(sprintf('The role creation writer writes RoleCreated, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::CREATE, [
            $mutation->role->toString(),
            $mutation->handle->value,
            $mutation->ceiling->value,
            RoleRows::permissions($mutation->permissions),
            $context->version->value,
            Timestamps::of($context->at),
            $context->changesetId->toString(),
        ]);

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use Cbox\Cms\Core\Access\Domain\Events\GrantChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChangedV1;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes GrantAssigned in the commit (PRD 5.10, 6.2 phase 7): the grant at the context's version,
 * created at the changeset's time. It returns grant.changed.
 *
 * The app role writes no grant itself, so the writer calls ASSIGN, which runs as the owner role and
 * only in the transaction of a grant.assign changeset by the context's actor (see the migration
 * that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the grant's id, its slot, the actor and the role.
 */
#[Internal]
final readonly class GrantAssignedWriter implements MutationWriter
{
    /** The creation of one grant, as the owner role. */
    public const string ASSIGN = 'select cms_access_assign_grant(?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::text[], ?::bigint, ?::timestamptz, ?::uuid)';

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
        return GrantAssigned::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof GrantAssigned) {
            throw new InvalidArgumentException(sprintf('The grant assignment writer writes GrantAssigned, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::ASSIGN, [
            $mutation->grant->toString(),
            $mutation->actor->toString(),
            $mutation->role->toString(),
            $mutation->node->toString(),
            $mutation->effect->value,
            GrantRows::literal($mutation->locales),
            $context->version->value,
            Timestamps::of($context->at),
            $context->changesetId->toString(),
        ]);

        return [new GrantChanged($mutation->grant, $context->version->value, new GrantChangedV1($mutation->actor, $mutation->role, $mutation->node))];
    }
}

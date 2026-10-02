<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRevoked;
use Cbox\Cms\Core\Access\Domain\Events\GrantChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChangedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes GrantRevoked in the commit (PRD 5.10, 5.16, 6.2 phase 7): the grant ends with the
 * changeset at the context's version, as a deactivation ends an actor's grants, and stays. It
 * returns grant.changed with the grant's actor, role and node.
 *
 * The app role writes no grant itself, so the writer calls REVOKE, which runs as the owner role and
 * only in the transaction of a grant.revoke changeset by the context's actor (see the migration
 * that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the grant and checked its version.
 */
#[Internal]
final readonly class GrantRevokedWriter implements MutationWriter
{
    /** The end of one grant, as the owner role. */
    public const string REVOKE = 'select actor_id::text as actor_id, role_id::text as role_id, node_id::text as node_id from cms_access_revoke_grant(?::uuid, ?::bigint, ?::uuid)';

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
        return GrantRevoked::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof GrantRevoked) {
            throw new InvalidArgumentException(sprintf('The grant revocation writer writes GrantRevoked, not %s.', $mutation::class));
        }

        $row = GrantRows::row($this->connections->connection($this->connection)->selectOne(self::REVOKE, [
            $mutation->grant->toString(),
            $context->version->value,
            $context->changesetId->toString(),
        ], false));

        return [new GrantChanged($mutation->grant, $context->version->value, new GrantChangedV1(
            ActorId::fromString(GrantRows::text($row, 'actor_id')),
            RoleId::fromString(GrantRows::text($row, 'role_id')),
            NodeId::fromString(GrantRows::text($row, 'node_id')),
        ))];
    }
}

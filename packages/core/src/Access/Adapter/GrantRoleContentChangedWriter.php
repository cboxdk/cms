<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRoleContentChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChangedV1;
use Cbox\Cms\Core\Pipeline\Domain\BatchMutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Writes GrantRoleContentChanged in the commit (PRD 5.10, 6.2 phase 7): each grant moves to the
 * context's version and keeps everything else, and the writer returns grant.changed with its actor,
 * role and node, so whatever compiled the actor's access compiles it again. A run of them, every
 * grant of a role whose permissions change, is one statement.
 *
 * The app role writes no grant itself, so the writer calls CHANGE, which runs as the owner role and
 * only in the transaction of a role.set_permissions changeset by the context's actor (see the
 * migration that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked each grant and checked its version.
 */
#[Internal]
final readonly class GrantRoleContentChangedWriter implements BatchMutationWriter
{
    /** The move of several grants to their next versions, as the owner role. */
    public const string CHANGE = 'select id::text as id, actor_id::text as actor_id, role_id::text as role_id, node_id::text as node_id from cms_access_role_grants_changed(?::uuid[], ?::bigint[], ?::uuid)';

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
        return GrantRoleContentChanged::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        return $this->writeAll([new PendingMutation($mutation, $context)]);
    }

    #[Override]
    public function writeAll(array $mutations): array
    {
        $grants = [];
        $versions = [];
        $changeset = $mutations[0]->context->changesetId;

        foreach ($mutations as $pending) {
            if (! $pending->mutation instanceof GrantRoleContentChanged) {
                throw new InvalidArgumentException(sprintf('The grant role change writer writes GrantRoleContentChanged, not %s.', $pending->mutation::class));
            }

            $grants[] = $pending->mutation->grant->toString();
            $versions[$pending->mutation->grant->toString()] = $pending->context->version->value;
        }

        $rows = $this->connections->connection($this->connection)->select(self::CHANGE, [
            '{'.implode(',', $grants).'}',
            '{'.implode(',', array_values($versions)).'}',
            $changeset->toString(),
        ], false);
        $changed = [];

        foreach ($rows as $row) {
            $row = GrantRows::row($row);
            $changed[GrantRows::text($row, 'id')] = new GrantChangedV1(
                ActorId::fromString(GrantRows::text($row, 'actor_id')),
                RoleId::fromString(GrantRows::text($row, 'role_id')),
                NodeId::fromString(GrantRows::text($row, 'node_id')),
            );
        }

        $events = [];

        foreach ($versions as $grant => $version) {
            $payload = $changed[$grant] ?? throw new UnexpectedValueException(sprintf('The grant %s did not move to version %d.', $grant, $version));
            $events[] = new GrantChanged(GrantId::fromString($grant), $version, $payload);
        }

        return $events;
    }
}

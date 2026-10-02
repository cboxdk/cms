<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * The GrantReader on Postgres (PRD 5.10), on the default connection, or the one named, inside the
 * command transaction and under its actor context. The app role reads only its own actor's grants,
 * so a grant and a grant's slot are read through the owner functions cms_access_grant (only where
 * the context's regions reach the grant's node) and cms_access_grant_held; every actor reads the
 * roles and their permissions itself. Each read is one statement, on the write PDO.
 */
#[Internal]
final readonly class PostgresGrantReader implements GrantReader
{
    /** One grant the context reaches, as the owner role. */
    public const string GRANT = 'select actor_id::text as actor_id, role_id::text as role_id, node_id::text as node_id, effect, locales::text as locales, version, ended from cms_access_grant(?::uuid)';

    /** Whether the slot holds a grant that has not ended, as the owner role. */
    public const string HELD = 'select cms_access_grant_held(?::uuid, ?::uuid, ?::uuid) as held';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function grant(GrantId $grant): ?StoredGrant
    {
        $row = $this->db()->selectOne(self::GRANT, [$grant->toString()], false);

        if ($row === null) {
            return null;
        }

        $row = GrantRows::row($row);

        return new StoredGrant(
            $grant,
            ActorId::fromString(GrantRows::text($row, 'actor_id')),
            RoleId::fromString(GrantRows::text($row, 'role_id')),
            NodeId::fromString(GrantRows::text($row, 'node_id')),
            GrantEffect::from(GrantRows::text($row, 'effect')),
            GrantRows::locales($row, 'locales'),
            new AggregateVersion(GrantRows::integer($row, 'version')),
            GrantRows::boolean($row, 'ended'),
        );
    }

    #[Override]
    public function role(RoleId $role): ?StoredRole
    {
        $rows = $this->db()->table('roles as r')
            ->useWritePdo()
            ->leftJoin('role_permissions as p', 'p.role_id', '=', 'r.id')
            ->where('r.id', $role->toString())
            ->orderBy('p.command')
            ->get(['r.classification_ceiling', 'r.version', 'p.command']);

        if ($rows->isEmpty()) {
            return null;
        }

        $permissions = [];
        $first = GrantRows::row($rows->first());

        foreach ($rows as $row) {
            $command = GrantRows::text(GrantRows::row($row), 'command', nullable: true);

            if ($command !== null) {
                $permissions[] = new CommandName($command);
            }
        }

        return new StoredRole(
            $role,
            ClassificationAccess::from(GrantRows::text($first, 'classification_ceiling')),
            $permissions,
            new AggregateVersion(GrantRows::integer($first, 'version')),
        );
    }

    #[Override]
    public function held(GrantSlotRef $slot): bool
    {
        $row = GrantRows::row($this->db()->selectOne(self::HELD, [$slot->actor->toString(), $slot->role->toString(), $slot->node->toString()], false));

        return GrantRows::boolean($row, 'held');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

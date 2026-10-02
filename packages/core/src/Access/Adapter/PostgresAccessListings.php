<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\AccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\ListedRole;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedProfile;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * AccessListings on Postgres (PRD 5.10), on the default connection, or the one named, inside the
 * read transaction and under its actor context, on the write PDO. The roles are read by the app
 * role itself, which every actor context may (`roles_read`, `role_permissions_read`), in two
 * statements: the page of roles, then their permissions. The grants are read in one statement
 * through the owner function cms_access_grant_list, because the app role reads only its own
 * actor's grants and a path label names nodes above the ones the context reaches.
 */
#[Internal]
final readonly class PostgresAccessListings implements AccessListings
{
    /** A page of grants the context reaches, as the owner role. */
    public const string GRANTS = 'select id, actor_id, role_id, role_handle, node_id, node_label, effect, locales, version, display_name, email from cms_access_grant_list(?::uuid, ?)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function roles(?RoleId $after, int $limit): array
    {
        $query = $this->db()->table('roles')->useWritePdo()->orderBy('id')->limit($limit);

        if ($after instanceof RoleId) {
            $query->where('id', '>', $after->toString());
        }

        $rows = $query->get(['id', 'handle', 'classification_ceiling', 'version']);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = GrantRows::text(GrantRows::row($row), 'id');
        }

        $permissions = [];

        foreach ($this->db()->table('role_permissions')->useWritePdo()->whereIn('role_id', $ids)->orderBy('role_id')->orderBy('command')->get(['role_id', 'command']) as $row) {
            $row = GrantRows::row($row);
            $permissions[GrantRows::text($row, 'role_id')][] = new CommandName(GrantRows::text($row, 'command'));
        }

        $roles = [];

        foreach ($rows as $row) {
            $row = GrantRows::row($row);
            $id = GrantRows::text($row, 'id');
            $roles[] = new ListedRole(
                RoleId::fromString($id),
                new RoleHandle(GrantRows::text($row, 'handle')),
                ClassificationAccess::from(GrantRows::text($row, 'classification_ceiling')),
                $permissions[$id] ?? [],
                new AggregateVersion(GrantRows::integer($row, 'version')),
            );
        }

        return $roles;
    }

    #[Override]
    public function grants(?GrantId $after, int $limit): array
    {
        $grants = [];

        foreach ($this->db()->select(self::GRANTS, [$after?->toString(), $limit], false) as $row) {
            $row = GrantRows::row($row);
            $name = GrantRows::text($row, 'display_name', nullable: true);
            $email = GrantRows::text($row, 'email', nullable: true);
            $grants[] = new ListedGrant(
                GrantId::fromString(GrantRows::text($row, 'id')),
                ActorId::fromString(GrantRows::text($row, 'actor_id')),
                $name === null || $email === null ? null : new ListedProfile(new DisplayName($name), new EmailAddress($email)),
                RoleId::fromString(GrantRows::text($row, 'role_id')),
                new RoleHandle(GrantRows::text($row, 'role_handle')),
                NodeId::fromString(GrantRows::text($row, 'node_id')),
                GrantRows::text($row, 'node_label'),
                GrantEffect::from(GrantRows::text($row, 'effect')),
                GrantRows::locales($row, 'locales'),
                new AggregateVersion(GrantRows::integer($row, 'version')),
            );
        }

        return $grants;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

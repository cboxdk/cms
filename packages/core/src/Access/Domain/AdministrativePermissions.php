<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * Which permissions make a role administrative (PRD 5.16): those whose holder can change roles,
 * grants, the identity mapping or connections, or who is active. A permission counts only when the
 * registry has a command, a write, of its name, through the PermissionCatalog, and that command is
 * in the grant, role or identity namespace or changes who is active (actor.activate,
 * actor.deactivate, actor.reactivate). A query such as role.list or grant.list changes nothing,
 * so a role that may only read roles and grants is not administrative.
 */
#[Internal]
final readonly class AdministrativePermissions
{
    /**
     * The namespaces whose commands change roles, grants, the identity mapping or connections.
     *
     * @var list<string>
     */
    public const array NAMESPACES = ['grant', 'role', 'identity'];

    /**
     * The commands that change who is active.
     *
     * @var list<string>
     */
    public const array COMMANDS = ['actor.activate', 'actor.deactivate', 'actor.reactivate'];

    public function __construct(private PermissionCatalog $catalog) {}

    /**
     * Whether a role with these permissions is administrative: one of them is a command of the
     * registry that changes roles, grants, the identity mapping, connections or who is active.
     *
     * @param  list<CommandName>  $permissions
     */
    public function any(array $permissions): bool
    {
        return array_any($permissions, fn (CommandName $permission): bool => $this->is($permission));
    }

    /**
     * Whether the permission makes a role administrative.
     */
    public function is(CommandName $permission): bool
    {
        if (! $this->catalog->writes($permission)) {
            return false;
        }

        if (in_array($permission->value, self::COMMANDS, true)) {
            return true;
        }

        $namespace = strstr($permission->value, '.', true);

        return in_array($namespace, self::NAMESPACES, true);
    }
}

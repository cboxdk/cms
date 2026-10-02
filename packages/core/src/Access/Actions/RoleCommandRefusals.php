<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;

/**
 * What the role commands share (PRD 5.10): the names of a list of permissions the registry does
 * not know, and the refusals of a list that names a permission the registry does not know or one
 * twice, each validation_failed at the permission's place in the list.
 */
#[Internal]
final readonly class RoleCommandRefusals
{
    /**
     * The permissions the catalog knows, in the list's order.
     *
     * @param  list<CommandName>  $permissions
     * @return list<CommandName>
     */
    public static function known(PermissionCatalog $catalog, array $permissions): array
    {
        return array_values(array_filter($permissions, $catalog->has(...)));
    }

    /**
     * The permissions the catalog does not know, in the list's order.
     *
     * @param  list<CommandName>  $permissions
     * @return list<CommandName>
     */
    public static function unknown(PermissionCatalog $catalog, array $permissions): array
    {
        return array_values(array_filter($permissions, static fn (CommandName $permission): bool => ! $catalog->has($permission)));
    }

    /**
     * @param  list<CommandName>  $permissions  the list the command gives
     * @param  list<CommandName>  $unknown  the names of it the registry does not know
     * @return list<CatalogError>
     */
    public static function of(array $permissions, array $unknown): array
    {
        $refusals = [];
        $unknownNames = [];
        $seen = [];

        foreach ($unknown as $permission) {
            $unknownNames[$permission->value] = true;
        }

        foreach ($permissions as $index => $permission) {
            if (isset($unknownNames[$permission->value])) {
                $refusals[] = new CatalogError(ErrorCode::ValidationFailed, new FieldPath('permissions', $index), sprintf(
                    'No command or query of the registry is named %s, so a role may not run it; cms:actions lists the names.',
                    $permission->value,
                ));
            } elseif (isset($seen[$permission->value])) {
                $refusals[] = new CatalogError(ErrorCode::ValidationFailed, new FieldPath('permissions', $index), sprintf(
                    'A role\'s permissions name each command or query once, and %s twice.',
                    $permission->value,
                ));
            }

            $seen[$permission->value] = true;
        }

        return $refusals;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The bootstrap role (PRD 5.10): every command and query of the compiled registry, each once and
 * sorted, with the ceiling sensitive, so the first staff member can run and read everything the
 * installation has and give others access from there.
 */
#[Internal]
final readonly class BootstrapRole
{
    public const ClassificationAccess CEILING = ClassificationAccess::Sensitive;

    /**
     * Every #[Command] the registry holds and the command or query of every action, each once and
     * sorted by name.
     *
     * @return list<CommandName>
     */
    public static function permissions(CompiledRegistry $registry): array
    {
        $names = [];

        foreach ($registry->commands as $command) {
            $names[$command->name->value] = $command->name;
        }

        foreach ($registry->actions as $action) {
            $names[$action->command->value] = $action->command;
        }

        ksort($names, SORT_STRING);

        return array_values($names);
    }

    /**
     * Whether a role that has the bootstrap role's handle already is the bootstrap role: the ceiling
     * sensitive and every one of the permissions given.
     *
     * @param  list<CommandName>  $permissions
     */
    public static function covers(ExistingRole $role, array $permissions): bool
    {
        if ($role->ceiling !== self::CEILING) {
            return false;
        }

        $held = [];

        foreach ($role->permissions as $permission) {
            $held[$permission->value] = true;
        }

        return array_all($permissions, fn (CommandName $permission): bool => isset($held[$permission->value]));
    }
}

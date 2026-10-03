<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Override;

/**
 * The names of the compiled registry (PRD 5.10, 13.2): every #[Command] cms:build registered, and
 * the command or query every action handles, so a query, which the registry holds through its
 * action, is a name too. A name is a write when a #[Command] or a write action has it.
 */
#[Internal]
final readonly class RegistryPermissionCatalog implements PermissionCatalog
{
    /** @var array<string, true> */
    private array $names;

    /** @var array<string, true> */
    private array $writes;

    public function __construct(CompiledRegistry $registry)
    {
        $names = [];
        $writes = [];

        foreach ($registry->commands as $command) {
            $names[$command->name->value] = true;
            $writes[$command->name->value] = true;
        }

        foreach ($registry->actions as $action) {
            $names[$action->command->value] = true;

            if ($action->kind === ActionKind::Write) {
                $writes[$action->command->value] = true;
            }
        }

        $this->names = $names;
        $this->writes = $writes;
    }

    #[Override]
    public function has(CommandName $name): bool
    {
        return isset($this->names[$name->value]);
    }

    #[Override]
    public function writes(CommandName $name): bool
    {
        return isset($this->writes[$name->value]);
    }
}

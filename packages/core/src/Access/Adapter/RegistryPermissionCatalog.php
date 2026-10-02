<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Override;

/**
 * The names of the compiled registry (PRD 5.10, 13.2): every #[Command] cms:build registered, and
 * the command or query every action handles, so a query, which the registry holds through its
 * action, is a name too.
 */
#[Internal]
final readonly class RegistryPermissionCatalog implements PermissionCatalog
{
    /** @var array<string, true> */
    private array $names;

    public function __construct(CompiledRegistry $registry)
    {
        $names = [];

        foreach ($registry->commands as $command) {
            $names[$command->name->value] = true;
        }

        foreach ($registry->actions as $action) {
            $names[$action->command->value] = true;
        }

        $this->names = $names;
    }

    #[Override]
    public function has(CommandName $name): bool
    {
        return isset($this->names[$name->value]);
    }
}

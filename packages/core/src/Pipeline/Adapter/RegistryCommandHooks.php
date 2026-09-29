<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Pipeline\Domain\InvalidHook;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * The hooks of the compiled registry (PRD 13.2): each hook cms:build registered for the name and
 * version of the command, built by the container, with its package, phase, priority and budget
 * from its entry, and for a hook of an addon the classification its manifest lets it read.
 */
#[Internal]
final readonly class RegistryCommandHooks implements CommandHooks
{
    public function __construct(
        private CompiledRegistry $registry,
        private Container $container,
    ) {}

    #[Override]
    public function for(CommandName $command, int $version): array
    {
        $hooks = [];

        foreach ($this->registry->hooks as $entry) {
            if ($entry->command->equals($command) && $entry->commandVersion === $version) {
                $hooks[] = $this->bind($entry);
            }
        }

        return $hooks;
    }

    private function bind(HookEntry $entry): BoundHook
    {
        $hook = $this->container->make($entry->class);

        if (! $hook instanceof AuthorizeHook && ! $hook instanceof TransformHook && ! $hook instanceof ValidateHook) {
            throw InvalidHook::phase($entry->class, $entry->phase);
        }

        return new BoundHook($hook, $entry->package, $entry->phase, $entry->priority, $entry->budgetMs, $entry->reads);
    }
}

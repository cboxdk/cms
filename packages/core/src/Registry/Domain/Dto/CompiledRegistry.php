<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\RegistryName;

/**
 * The registries cms:build compiles and the application reads at run time (PRD 13.2).
 *
 * Actions are sorted by the name, then the version, of the command or query they handle. Commands
 * are sorted by name, then version. Hooks are sorted by command name and version, then phase in
 * pipeline order (authorize, transform, validate), then priority with the lowest first, then
 * package, then class.
 */
#[Experimental]
final readonly class CompiledRegistry
{
    /** @var array<string, ActionEntry> the actions by the name and version they handle */
    private array $byCommand;

    /** @var array<string, ActionEntry> the actions by the lower-case class they handle */
    private array $byClass;

    /**
     * @param  list<CommandEntry>  $commands
     * @param  list<HookEntry>  $hooks
     * @param  list<ActionEntry>  $actions
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $actions = [],
    ) {
        $byCommand = [];
        $byClass = [];

        foreach ($actions as $action) {
            $byCommand[$this->key($action->command, $action->commandVersion)] ??= $action;
            $byClass[strtolower($action->commandClass)] ??= $action;
        }

        $this->byCommand = $byCommand;
        $this->byClass = $byClass;
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function count(RegistryName $registry): int
    {
        return match ($registry) {
            RegistryName::Actions => count($this->actions),
            RegistryName::Commands => count($this->commands),
            RegistryName::Hooks => count($this->hooks),
        };
    }

    /**
     * The action that handles a version of a command or query, or null when none does.
     */
    public function action(CommandName $command, int $version): ?ActionEntry
    {
        return $this->byCommand[$this->key($command, $version)] ?? null;
    }

    /**
     * The action that handles a command or query class, or null when none does. PHP class names
     * are compared without case, as PHP compares them.
     */
    public function actionFor(string $commandClass): ?ActionEntry
    {
        return $this->byClass[strtolower(ltrim($commandClass, '\\'))] ?? null;
    }

    private function key(CommandName $command, int $version): string
    {
        return $command->value.'@'.$version;
    }
}

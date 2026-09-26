<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;

/**
 * Turns what the scanner found into the registry (PRD 13.2): checks that each command name and
 * version belongs to one class and that each hook runs for a registered command, and sorts every
 * list so the result depends only on the declarations.
 */
#[Experimental]
final readonly class RegistryCompiler
{
    /**
     * @throws RegistryBuildFailed with the scanner's problems and the compiler's own
     */
    public function compile(Discovery $discovery): CompiledRegistry
    {
        $problems = $discovery->problems;
        $commandsByClass = [];
        $declarations = [];

        foreach ($discovery->commands as $command) {
            $commandsByClass[strtolower($command->class)] = $command;
            $declarations[$command->name][$command->version][] = $command;
        }

        foreach ($declarations as $versions) {
            foreach ($versions as $sharing) {
                if (count($sharing) > 1) {
                    $problems[] = $this->duplicate($sharing);
                }
            }
        }

        $hooks = [];

        foreach ($discovery->hooks as $hook) {
            $command = $commandsByClass[strtolower($hook->commandClass)] ?? null;

            if (! $command instanceof CommandEntry) {
                $problems[] = new BuildProblem(BuildErrorCode::UnknownHookCommand, sprintf(
                    'Hook %s (%s) runs for command class %s, which no scan root registers. Declare the scan root of the package that holds the command in its service provider (DeclaresScanRoots), or point the hook at a registered command.',
                    $hook->class,
                    $hook->package,
                    $hook->commandClass,
                ));

                continue;
            }

            $hooks[] = new HookEntry(
                $hook->class,
                $hook->package,
                $command->name,
                $command->version,
                $command->class,
                $hook->phase,
                $hook->priority,
                $hook->budgetMs,
            );
        }

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        $actions = $discovery->actions;
        $commands = $discovery->commands;

        usort($actions, static fn (ActionEntry $a, ActionEntry $b): int => strcmp($a->class, $b->class));
        usort($commands, static fn (CommandEntry $a, CommandEntry $b): int => [$a->name, $a->version] <=> [$b->name, $b->version]);
        usort($hooks, static fn (HookEntry $a, HookEntry $b): int => [$a->command, $a->commandVersion, self::rank($a->phase), $a->priority, $a->package, $a->class]
            <=> [$b->command, $b->commandVersion, self::rank($b->phase), $b->priority, $b->package, $b->class]);

        return new CompiledRegistry($actions, $commands, $hooks);
    }

    /**
     * @param  list<CommandEntry>  $sharing  at least two
     */
    private function duplicate(array $sharing): BuildProblem
    {
        $classes = array_map(static fn (CommandEntry $command): string => sprintf('%s (%s)', $command->class, $command->package), $sharing);
        sort($classes, SORT_STRING);

        return new BuildProblem(BuildErrorCode::DuplicateCommand, sprintf(
            'Command "%s" version %d is declared by %s. A name and version belong to one class: give the new shape the next version, or rename one of the commands.',
            $sharing[0]->name,
            $sharing[0]->version,
            implode(' and ', $classes),
        ));
    }

    /**
     * The phase's place in the pipeline (PRD 6.2): authorize, transform, validate.
     */
    private static function rank(Phase $phase): int
    {
        return match ($phase) {
            Phase::Authorize => 0,
            Phase::Transform => 1,
            Phase::Validate => 2,
        };
    }
}

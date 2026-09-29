<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;

/**
 * Turns what the scanner found into the registry (PRD 13.2): checks that each command or query name
 * and version belongs to one class, that each hook runs for a registered command, that each write
 * action handles a registered command and each query action a registered query, and that no
 * command or query has two actions; then sorts every list so the result depends only on the
 * declarations.
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
        $queriesByClass = [];
        $declarations = [];

        foreach ($discovery->commands as $command) {
            $commandsByClass[strtolower($command->class)] = $command;
            $declarations[$command->name->value][$command->version][] = sprintf('%s (%s)', $command->class, $command->package);
        }

        foreach ($discovery->queries as $query) {
            $queriesByClass[strtolower($query->class)] = $query;
            $declarations[$query->name->value][$query->version][] = sprintf('%s (%s)', $query->class, $query->package);
        }

        foreach ($declarations as $name => $versions) {
            foreach ($versions as $version => $sharing) {
                if (count($sharing) > 1) {
                    $problems[] = $this->duplicate((string) $name, $version, $sharing);
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

        $actions = [];

        foreach ($discovery->actions as $action) {
            $handled = match ($action->kind) {
                ActionKind::Write => $commandsByClass[strtolower($action->handles)] ?? null,
                ActionKind::Query => $queriesByClass[strtolower($action->handles)] ?? null,
            };

            if ($handled === null) {
                $problems[] = $this->unknownTarget($action, isset($commandsByClass[strtolower($action->handles)]) || isset($queriesByClass[strtolower($action->handles)]));

                continue;
            }

            $actions[] = new ActionEntry(
                $action->class,
                $action->package,
                $action->kind,
                $handled->name,
                $handled->version,
                $handled->class,
                $action->surfaces,
            );
        }

        $problems = [...$problems, ...$this->duplicateActions($actions)];

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        $commands = $discovery->commands;

        usort($commands, static fn (CommandEntry $a, CommandEntry $b): int => [$a->name->value, $a->version] <=> [$b->name->value, $b->version]);
        usort($hooks, static fn (HookEntry $a, HookEntry $b): int => [$a->command->value, $a->commandVersion, self::rank($a->phase), $a->priority, $a->package, $a->class]
            <=> [$b->command->value, $b->commandVersion, self::rank($b->phase), $b->priority, $b->package, $b->class]);

        usort($actions, static fn (ActionEntry $a, ActionEntry $b): int => [$a->command->value, $a->commandVersion] <=> [$b->command->value, $b->commandVersion]);

        return new CompiledRegistry($commands, $hooks, $actions);
    }

    /**
     * @param  list<string>  $sharing  at least two classes with their packages
     */
    private function duplicate(string $name, int $version, array $sharing): BuildProblem
    {
        sort($sharing, SORT_STRING);

        return new BuildProblem(BuildErrorCode::DuplicateCommand, sprintf(
            'Command "%s" version %d is declared by %s. A name and version belong to one class: give the new shape the next version, or rename one of the commands.',
            $name,
            $version,
            implode(' and ', $sharing),
        ));
    }

    /**
     * @param  bool  $otherKind  whether the class is registered, as a query for a write action or a
     *                           command for a query action
     */
    private function unknownTarget(DiscoveredAction $action, bool $otherKind): BuildProblem
    {
        $attribute = $action->kind === ActionKind::Write ? '#[Command]' : '#[Query]';
        $interface = $action->kind === ActionKind::Write ? 'WriteAction' : 'QueryAction';

        return new BuildProblem(BuildErrorCode::UnknownActionCommand, sprintf(
            'Action %s (%s) is a %s and handles %s, which is %s. A %s handles a %s class declared with %s in a registered scan root: point #[Action(handles: ...)] at it, or declare the scan root of the package that holds it.',
            $action->class,
            $action->package,
            $interface,
            $action->handles,
            $otherKind
                ? sprintf('a registered %s, not a %s', $action->kind === ActionKind::Write ? 'query' : 'command', $action->kind->input())
                : sprintf('not a %s any scan root registers', $action->kind->input()),
            $interface,
            $action->kind->input(),
            $attribute,
        ));
    }

    /**
     * One problem per command or query version that more than one action handles.
     *
     * @param  list<ActionEntry>  $actions
     * @return list<BuildProblem>
     */
    private function duplicateActions(array $actions): array
    {
        $handlers = [];

        foreach ($actions as $action) {
            $handlers[$action->command->value][$action->commandVersion][] = sprintf('%s (%s)', $action->class, $action->package);
        }

        $problems = [];

        foreach ($handlers as $name => $versions) {
            foreach ($versions as $version => $classes) {
                if (count($classes) < 2) {
                    continue;
                }

                sort($classes, SORT_STRING);

                $problems[] = new BuildProblem(BuildErrorCode::DuplicateAction, sprintf(
                    '"%s" version %d is handled by %s. A command or query has one action: remove the #[Action] of all but one, or give the new shape its own version.',
                    $name,
                    $version,
                    implode(' and ', $classes),
                ));
            }
        }

        return $problems;
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

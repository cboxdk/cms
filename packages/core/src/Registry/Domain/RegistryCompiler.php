<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;

/**
 * Turns what the scanner found into the registry (PRD 13.2): checks that each command or query name
 * and version belongs to one class, that each hook runs for a registered command, that each write
 * action handles a registered command and each query action a registered query, that no command
 * or query has two actions, and that each subscription name belongs to one subscriber; then sorts
 * every list so the result depends only on the declarations.
 *
 * It compiles the addon manifests with them (PRD 13.1 to 13.3): a namespace and a package belong
 * to one manifest, and the kernel's API version must satisfy each manifest's. A hook or subscriber
 * of an addon's package must be one its manifest allows, and its entry names the addon; a hook's
 * also carries the classification the manifest lets the kernel hand it. Each manifest's schema
 * contributions become an entry of schema.php.
 *
 * The routes of the REST surface follow from the actions exposed on it (RestRoute::of()), one per
 * action, in the order of the actions.
 */
#[Experimental]
final readonly class RegistryCompiler
{
    /**
     * @throws RegistryBuildFailed with the scanner's problems and the compiler's own
     */
    public function compile(Discovery $discovery, DeclaredAddons $addons = new DeclaredAddons): CompiledRegistry
    {
        $problems = [...$discovery->problems, ...$addons->problems, ...$this->manifestProblems($addons->manifests)];
        $manifests = $this->manifests($addons->manifests);
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

            $manifest = $manifests[$hook->package] ?? null;

            if ($manifest instanceof AddonManifest && ! $manifest->allowsHook($command->class, $hook->phase)) {
                $problems[] = new BuildProblem(BuildErrorCode::UndeclaredHook, sprintf(
                    'Hook %s (%s) runs for %s (%s) in the %s phase, which the manifest of addon "%s" does not allow. Add new AllowedHook(%s::class, Phase::%s) to its hooks, or remove the #[Hook].',
                    $hook->class,
                    $hook->package,
                    $command->class,
                    $command->name->value,
                    $hook->phase->value,
                    $manifest->namespace->value,
                    $command->class,
                    $hook->phase->name,
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
                $manifest?->namespace,
                $manifest?->capabilities->reads,
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

        $subscribers = [];

        foreach ($discovery->subscribers as $subscriber) {
            $manifest = $manifests[$subscriber->package] ?? null;

            if (! $manifest instanceof AddonManifest) {
                $subscribers[] = $subscriber;

                continue;
            }

            $undeclared = array_values(array_filter($subscriber->events, static fn (SubscribedEvent $event): bool => ! $manifest->allowsSubscription($event->class, $subscriber->lane)));

            if ($undeclared !== []) {
                $problems[] = new BuildProblem(BuildErrorCode::UndeclaredSubscriber, sprintf(
                    'Subscriber %s (%s) receives %s on the %s lane, which the manifest of addon "%s" does not allow. Add an AllowedSubscription with Lane::%s for each to its subscriptions, or stop receiving them.',
                    $subscriber->class,
                    $subscriber->package,
                    implode(', ', array_map(static fn (SubscribedEvent $event): string => $event->class, $undeclared)),
                    $subscriber->lane->value,
                    $manifest->namespace->value,
                    $subscriber->lane->name,
                ));

                continue;
            }

            $subscribers[] = new SubscriberEntry($subscriber->class, $subscriber->package, $subscriber->name, $subscriber->lane, $subscriber->projection, $subscriber->events, $manifest->namespace);
        }

        $problems = [...$problems, ...$this->duplicateActions($actions), ...$this->duplicateSubscriptions($discovery->subscribers)];

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        $commands = $discovery->commands;

        usort($commands, static fn (CommandEntry $a, CommandEntry $b): int => [$a->name->value, $a->version] <=> [$b->name->value, $b->version]);
        usort($hooks, static fn (HookEntry $a, HookEntry $b): int => [$a->command->value, $a->commandVersion, self::rank($a->phase), $a->priority, $a->package, $a->class]
            <=> [$b->command->value, $b->commandVersion, self::rank($b->phase), $b->priority, $b->package, $b->class]);

        usort($actions, static fn (ActionEntry $a, ActionEntry $b): int => [$a->command->value, $a->commandVersion] <=> [$b->command->value, $b->commandVersion]);

        usort($subscribers, static fn (SubscriberEntry $a, SubscriberEntry $b): int => strcmp($a->name->value, $b->name->value));

        $schema = array_map(static fn (AddonManifest $manifest): SchemaEntry => new SchemaEntry(
            $manifest->namespace,
            $manifest->package,
            $manifest->schema->fieldTypes,
            $manifest->schema->types,
            $manifest->schema->extends,
            $manifest->schema->fieldTypeContributor,
        ), array_values($manifests));

        usort($schema, static fn (SchemaEntry $a, SchemaEntry $b): int => strcmp($a->namespace->value, $b->namespace->value));

        $rest = array_values(array_filter(array_map(RestRoute::of(...), $actions), static fn (?RestRoute $route): bool => $route instanceof RestRoute));

        return new CompiledRegistry($commands, $hooks, $actions, $subscribers, $schema, $rest);
    }

    /**
     * The manifests by package, the first of each package.
     *
     * @param  list<AddonManifest>  $manifests
     * @return array<string, AddonManifest>
     */
    private function manifests(array $manifests): array
    {
        $byPackage = [];

        foreach ($manifests as $manifest) {
            $byPackage[$manifest->package] ??= $manifest;
        }

        return $byPackage;
    }

    /**
     * The problems of the manifests among themselves and with the kernel: a package or a namespace
     * of two manifests, and a core API version the kernel does not satisfy.
     *
     * @param  list<AddonManifest>  $manifests
     * @return list<BuildProblem>
     */
    private function manifestProblems(array $manifests): array
    {
        $problems = [];
        $packages = [];
        $namespaces = [];
        $kernel = CoreApiVersion::current();

        foreach ($manifests as $manifest) {
            $packages[$manifest->package][] = $manifest->namespace->value;
            $namespaces[$manifest->namespace->value][] = $manifest->package;

            if (! $manifest->coreApi->satisfiedBy($kernel)) {
                $problems[] = new BuildProblem(BuildErrorCode::IncompatibleCoreApi, sprintf(
                    'Addon "%s" (%s) needs the core API %s, and this kernel has %s. Install a version of the addon made for core API %s, or a kernel whose API it needs.',
                    $manifest->namespace->value,
                    $manifest->package,
                    $manifest->coreApi->constraint(),
                    $kernel->toString(),
                    $kernel->constraint(),
                ));
            }
        }

        foreach ($packages as $package => $declared) {
            if (count($declared) > 1) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf(
                    'The package %s declares %d addon manifests (%s). A package is one addon: declare one manifest from one service provider.',
                    $package,
                    count($declared),
                    implode(', ', $declared),
                ));
            }
        }

        foreach ($namespaces as $namespace => $declaring) {
            if (count($declaring) > 1) {
                sort($declaring, SORT_STRING);

                $problems[] = new BuildProblem(BuildErrorCode::DuplicateNamespace, sprintf(
                    'The addon namespace "%s" is declared by %s. A namespace belongs to one addon in the installation, because it holds the addon\'s fields and field types (PRD 13.1, 13.3): remove one of the addons.',
                    $namespace,
                    implode(' and ', $declaring),
                ));
            }
        }

        return $problems;
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
     * One problem per subscription name that more than one subscriber declares.
     *
     * @param  list<SubscriberEntry>  $subscribers
     * @return list<BuildProblem>
     */
    private function duplicateSubscriptions(array $subscribers): array
    {
        $declared = [];

        foreach ($subscribers as $subscriber) {
            $declared[$subscriber->name->value][] = sprintf('%s (%s)', $subscriber->class, $subscriber->package);
        }

        $problems = [];

        foreach ($declared as $name => $classes) {
            if (count($classes) < 2) {
                continue;
            }

            sort($classes, SORT_STRING);

            $problems[] = new BuildProblem(BuildErrorCode::DuplicateSubscription, sprintf(
                'Subscription "%s" is declared by %s. The event log keeps a subscription\'s cursor under its name, so a name belongs to one subscriber: rename one of the subscriptions.',
                $name,
                implode(' and ', $classes),
            ));
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

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\RegistryName;

/**
 * The registries cms:build compiles and the application reads at run time (PRD 13.2).
 *
 * Actions are sorted by the name, then the version, of the command or query they handle. Commands
 * are sorted by name, then version. Hooks are sorted by command name and version, then phase in
 * pipeline order (authorize, transform, validate), then priority with the lowest first, then
 * package, then class. Subscribers are sorted by subscription name. Schema contributions are sorted
 * by the addon's namespace.
 *
 * The REST routes are compiled from the actions exposed on REST (RestRoute::of()), in the order of
 * the actions. The panel points are sorted by name, then version, each with its contributions in
 * render order. The addons are sorted by namespace.
 *
 * The warnings are what the build told the installation without refusing to build (BuildWarning);
 * the cache does not hold them, so a registry read from it has none.
 *
 * The registry answers which subscribers receive an event class and which projections they
 * acknowledge, so the kernel can list on a changeset's receipt each projection its events affect
 * (PRD 8.4).
 */
#[Experimental]
final readonly class CompiledRegistry
{
    /** @var array<string, ActionEntry> the actions by the name and version they handle */
    private array $byCommand;

    /** @var array<string, ActionEntry> the actions by the lower-case class they handle */
    private array $byClass;

    /** @var array<string, list<SubscriberEntry>> the subscribers by the lower-case event class they receive */
    private array $byEvent;

    /**
     * @param  list<CommandEntry>  $commands
     * @param  list<HookEntry>  $hooks
     * @param  list<ActionEntry>  $actions
     * @param  list<SubscriberEntry>  $subscribers
     * @param  list<SchemaEntry>  $schema
     * @param  list<RestRoute>  $rest
     * @param  list<PanelPointEntry>  $panel
     * @param  list<AddonEntry>  $addons
     * @param  list<BuildWarning>  $warnings
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $actions = [],
        public array $subscribers = [],
        public array $schema = [],
        public array $rest = [],
        public array $panel = [],
        public array $addons = [],
        public array $warnings = [],
    ) {
        $byCommand = [];
        $byClass = [];

        foreach ($actions as $action) {
            $byCommand[$this->key($action->command, $action->commandVersion)] ??= $action;
            $byClass[strtolower($action->commandClass)] ??= $action;
        }

        $this->byCommand = $byCommand;
        $this->byClass = $byClass;

        $byEvent = [];

        foreach ($subscribers as $subscriber) {
            foreach ($subscriber->events as $event) {
                $byEvent[strtolower($event->class)][] = $subscriber;
            }
        }

        $this->byEvent = $byEvent;
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function count(RegistryName $registry): int
    {
        return match ($registry) {
            RegistryName::Actions => count($this->actions),
            RegistryName::Addons => count($this->addons),
            RegistryName::Commands => count($this->commands),
            RegistryName::Hooks => count($this->hooks),
            RegistryName::Rest => count($this->rest),
            RegistryName::Schema => count($this->schema),
            RegistryName::Subscribers => count($this->subscribers),
            RegistryName::Panel => count($this->panel),
        };
    }

    /**
     * The panel point with an id, or null when no class declares it.
     */
    public function panelPoint(PointId $id): ?PanelPointEntry
    {
        foreach ($this->panel as $point) {
            if ($point->id()->equals($id)) {
                return $point;
            }
        }

        return null;
    }

    /**
     * The installed addon with the namespace, or null when no manifest declares it.
     */
    public function addon(AddonNamespace $namespace): ?AddonEntry
    {
        foreach ($this->addons as $addon) {
            if ($addon->namespace->equals($namespace)) {
                return $addon;
            }
        }

        return null;
    }

    /**
     * The panel points a page renders, in registry order.
     *
     * @return list<PanelPointEntry>
     */
    public function panelPointsOf(PageName $page): array
    {
        return array_values(array_filter($this->panel, static fn (PanelPointEntry $point): bool => $point->page()->equals($page)));
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

    /**
     * The hooks that run for a version of a command, in the order they run: the registry's order,
     * phase in pipeline order, then priority with the lowest first, then package, then class (PRD
     * 6.3). The pipeline and cms:hooks both read them here, so the map shows the order they run in.
     *
     * @return list<HookEntry>
     */
    public function hooksOf(CommandName $command, int $version): array
    {
        return array_values(array_filter(
            $this->hooks,
            static fn (HookEntry $hook): bool => $hook->command->equals($command) && $hook->commandVersion === $version,
        ));
    }

    /**
     * The subscribers that receive events of a class, in registry order. PHP class names are
     * compared without case, as PHP compares them.
     *
     * @return list<SubscriberEntry>
     */
    public function subscribersOf(string $eventClass): array
    {
        return $this->byEvent[strtolower(ltrim($eventClass, '\\'))] ?? [];
    }

    /**
     * The projections an event class affects: those its subscribers acknowledge on the receipt,
     * each once, sorted by name. A subscriber without a projection adds none, and an event class
     * no subscriber receives affects none.
     *
     * @return list<ProjectionName>
     */
    public function projectionsFor(string $eventClass): array
    {
        $projections = [];

        foreach ($this->subscribersOf($eventClass) as $subscriber) {
            if ($subscriber->projection instanceof ProjectionName) {
                $projections[$subscriber->projection->value] = $subscriber->projection;
            }
        }

        ksort($projections, SORT_STRING);

        return array_values($projections);
    }

    private function key(CommandName $command, int $version): string
    {
        return $command->value.'@'.$version;
    }
}

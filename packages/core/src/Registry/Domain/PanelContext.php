<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;

/**
 * What PanelCompiler looks contributions up in: the declared points by id, the registered
 * commands, queries and hooks by class and by name, the actions, and the contracts' schemas.
 * PHP class names are compared without case, as PHP compares them.
 */
#[Internal]
final readonly class PanelContext
{
    /** @var list<PanelPointEntry> sorted by name, then version */
    public array $points;

    /** @var array<string, PanelPointEntry> */
    private array $pointsById;

    /** @var array<string, CommandEntry> by lower-case class */
    private array $commandsByClass;

    /** @var array<string, CommandEntry> by `<name>@<version>` */
    private array $commandsByRef;

    /** @var array<string, QueryEntry> by lower-case class */
    private array $queriesByClass;

    /** @var array<string, true> the names of every command and query */
    private array $names;

    /** @var array<string, DiscoveredHook> by lower-case class */
    private array $hooksByClass;

    /** @var array<string, ActionEntry> by `<name>@<version>` of what each handles */
    private array $actions;

    /**
     * @param  list<PanelPointEntry>  $points
     * @param  list<ActionEntry>  $actions
     */
    public function __construct(
        array $points,
        public Discovery $discovery,
        array $actions,
        public ContractShapes $shapes,
    ) {
        usort($points, static fn (PanelPointEntry $a, PanelPointEntry $b): int => [$a->declaration->name, $a->declaration->version] <=> [$b->declaration->name, $b->declaration->version]);
        $this->points = $points;

        $byId = [];

        foreach ($points as $point) {
            $byId[$point->id()->toString()] ??= $point;
        }

        $this->pointsById = $byId;
        $byClass = [];
        $byRef = [];
        $names = [];

        foreach ($discovery->commands as $command) {
            $byClass[strtolower($command->class)] = $command;
            $byRef[$command->name->value.'@'.$command->version] = $command;
            $names[$command->name->value] = true;
        }

        $queries = [];

        foreach ($discovery->queries as $query) {
            $queries[strtolower($query->class)] = $query;
            $names[$query->name->value] = true;
        }

        $hooks = [];

        foreach ($discovery->hooks as $hook) {
            $hooks[strtolower($hook->class)] ??= $hook;
        }

        $byHandled = [];

        foreach ($actions as $action) {
            $byHandled[$action->command->value.'@'.$action->commandVersion] ??= $action;
        }

        $this->commandsByClass = $byClass;
        $this->commandsByRef = $byRef;
        $this->queriesByClass = $queries;
        $this->names = $names;
        $this->hooksByClass = $hooks;
        $this->actions = $byHandled;
    }

    /**
     * The point id a text names, or null when it is not one.
     */
    public function pointIdOf(string $text): ?PointId
    {
        try {
            return PointId::fromString($text);
        } catch (InvalidPanelPoint) {
            return null;
        }
    }

    /**
     * The declared point with the id the text names, or null.
     */
    public function point(string $text): ?PanelPointEntry
    {
        $id = $this->pointIdOf($text);

        return $id instanceof PointId ? ($this->pointsById[$id->toString()] ?? null) : null;
    }

    public function command(CommandRef $command): ?CommandEntry
    {
        return $this->commandsByRef[$command->toString()] ?? null;
    }

    public function commandOfClass(string $class): ?CommandEntry
    {
        return $this->commandsByClass[strtolower(ltrim($class, '\\'))] ?? null;
    }

    public function queryOfClass(string $class): ?QueryEntry
    {
        return $this->queriesByClass[strtolower(ltrim($class, '\\'))] ?? null;
    }

    public function hookOfClass(string $class): ?DiscoveredHook
    {
        return $this->hooksByClass[strtolower(ltrim($class, '\\'))] ?? null;
    }

    /**
     * Whether a command or query of the name is registered, in any version.
     */
    public function knowsName(CommandName $name): bool
    {
        return isset($this->names[$name->value]);
    }

    /**
     * Whether the command's action lists Surface::Inertia, so the panel can run it.
     */
    public function exposedOnInertia(CommandEntry $command): bool
    {
        $action = $this->actions[$command->name->value.'@'.$command->version] ?? null;

        return $action instanceof ActionEntry && $action->kind === ActionKind::Write && $action->exposes(Surface::Inertia);
    }
}

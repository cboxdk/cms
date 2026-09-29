<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Ids\CommandName;
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
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $actions = [],
        public array $subscribers = [],
        public array $schema = [],
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
            RegistryName::Commands => count($this->commands),
            RegistryName::Hooks => count($this->hooks),
            RegistryName::Schema => count($this->schema),
            RegistryName::Subscribers => count($this->subscribers),
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

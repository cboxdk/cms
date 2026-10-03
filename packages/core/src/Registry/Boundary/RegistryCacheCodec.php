<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Subscribers\InvalidSubscriptionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use LogicException;

/**
 * The registry cache files of format 9, in both directions (PRD 13.2).
 *
 * A file is PHP that returns ['build' => '<sha256>', 'entries' => [...], 'format' => 9,
 * 'registry' => '<name>']. The keys of every array are written in alphabetical order, lists keep
 * the compiled order, and nothing depends on the time or the machine, so the same registry always
 * gives the same bytes. Reading checks every key and type and builds the typed entries; anything
 * else is MalformedRegistryCache.
 *
 * The build is the sha256 of the entries of every registry, so every file of one cms:build carries
 * the same build and files of builds with other entries do not. The files are replaced one at a
 * time, so a reader can meet files of two builds; decode() refuses them, and fromDifferentBuilds()
 * tells the reader to look again.
 */
#[Internal]
final readonly class RegistryCacheCodec
{
    public const int FORMAT = 9;

    private const string HEADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // Written by php artisan cms:build from the declared scan roots and addon manifests (PRD 13.2).
        // Do not edit and do not commit; run cms:build again instead.

        PHP;

    /**
     * @return array<string, string> the PHP source of each file, keyed by registry name
     */
    public function encode(CompiledRegistry $registry): array
    {
        $entries = $this->entries($registry);
        $build = $this->build($entries);
        $files = [];

        foreach (RegistryName::cases() as $name) {
            $files[$name->value] = self::HEADER."\nreturn ".$this->emit([
                'build' => $build,
                'entries' => $entries[$name->value],
                'format' => self::FORMAT,
                'registry' => $name->value,
            ], 0).";\n";
        }

        return $files;
    }

    /**
     * Whether the files come from builds with different entries, as they do when they were read
     * while cms:build replaced them. False when a file does not name its build, which decode()
     * reports.
     *
     * @param  array<string, mixed>  $files  what each file returned, keyed by registry name
     */
    public function fromDifferentBuilds(array $files): bool
    {
        $builds = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['build'] ?? null)) {
                return false;
            }

            $builds[$file['build']] = true;
        }

        return count($builds) > 1;
    }

    /**
     * The entries of each registry as the files hold them, keyed by registry name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function entries(CompiledRegistry $registry): array
    {
        return [
            RegistryName::Actions->value => array_map(static fn (ActionEntry $action): array => [
                'class' => $action->class,
                'command' => $action->command->value,
                'command_class' => $action->commandClass,
                'command_version' => $action->commandVersion,
                'kind' => $action->kind->value,
                'package' => $action->package,
                'surfaces' => array_map(static fn (Surface $surface): string => $surface->value, $action->surfaces),
            ], $registry->actions),
            RegistryName::Commands->value => array_map(static fn (CommandEntry $command): array => [
                'class' => $command->class,
                'name' => $command->name->value,
                'package' => $command->package,
                'version' => $command->version,
            ], $registry->commands),
            RegistryName::Hooks->value => array_map(static fn (HookEntry $hook): array => [
                'addon' => $hook->addon?->value,
                'budget_ms' => $hook->budgetMs,
                'class' => $hook->class,
                'command' => $hook->command->value,
                'command_class' => $hook->commandClass,
                'command_version' => $hook->commandVersion,
                'package' => $hook->package,
                'phase' => $hook->phase->value,
                'priority' => $hook->priority,
                'reads' => $hook->reads?->value,
            ], $registry->hooks),
            RegistryName::Panel->value => array_map(static fn (PanelPointEntry $point): array => [
                'class' => $point->class,
                'fills' => array_map(static fn (PanelFill $fill): array => [
                    'contribution' => $fill->contribution->value,
                    'package' => $fill->package,
                    'priority' => $fill->priority,
                    'scope' => [
                        'commands' => array_map(static fn (CommandRef $command): string => $command->toString(), $fill->scope->commands),
                        'field_types' => $fill->scope->fieldTypes,
                        'pages' => array_map(static fn (PageName $page): string => $page->value, $fill->scope->pages),
                        'requires' => $fill->scope->requires?->value,
                        'types' => array_map(static fn (TypeName $type): string => $type->value, $fill->scope->types),
                    ],
                ], $point->fills),
                'id' => $point->id()->toString(),
                'keyed_by' => $point->declaration->keyedBy?->value,
                'kind' => $point->declaration->kind->value,
                'label' => $point->declaration->label,
                'max' => $point->declaration->max,
                'multiplicity' => $point->declaration->multiplicity->value,
                'ownership' => $point->declaration->ownership?->value,
                'package' => $point->package,
                'page' => $point->declaration->page,
                'region' => $point->declaration->region?->value,
                'since' => $point->declaration->since,
                'stability' => $point->stability->value,
                'tightens' => array_map(static fn (Tighten $tighten): string => $tighten->value, $point->declaration->tightens),
            ], $registry->panel),
            RegistryName::Rest->value => array_map(static fn (RestRoute $route): array => [
                'kind' => $route->kind->value,
                'method' => $route->method->value,
                'name' => $route->name->value,
                'path' => $route->path,
                'version' => $route->version,
            ], $registry->rest),
            RegistryName::Schema->value => array_map(static fn (SchemaEntry $entry): array => [
                'extends' => array_map(static fn (TypeName $type): string => $type->value, $entry->extends),
                'field_type_contributor' => $entry->fieldTypeContributor,
                'field_types' => array_map(static fn (ContributedFieldType $type): string => $type->value, $entry->fieldTypes),
                'namespace' => $entry->namespace->value,
                'package' => $entry->package,
                'types' => array_map(static fn (TypeName $type): string => $type->value, $entry->types),
            ], $registry->schema),
            RegistryName::Subscribers->value => array_map(static fn (SubscriberEntry $subscriber): array => [
                'addon' => $subscriber->addon?->value,
                'class' => $subscriber->class,
                'events' => array_map(static fn (SubscribedEvent $event): array => [
                    'class' => $event->class,
                    'name' => $event->type->name,
                    'version' => $event->type->version,
                ], $subscriber->events),
                'lane' => $subscriber->lane->value,
                'name' => $subscriber->name->value,
                'package' => $subscriber->package,
                'projection' => $subscriber->projection?->value,
            ], $registry->subscribers),
        ];
    }

    /**
     * The sha256 of the entries of every registry, in the order of RegistryName.
     *
     * @param  array<string, list<array<string, mixed>>>  $entries  keyed by registry name
     */
    private function build(array $entries): string
    {
        $source = '';

        foreach (RegistryName::cases() as $name) {
            $source .= $name->value.' => '.$this->emit($entries[$name->value], 0).";\n";
        }

        return hash('sha256', $source);
    }

    /**
     * @param  array<string, mixed>  $files  what each file returned, keyed by registry name
     * @param  string  $directory  for the error messages
     *
     * @throws MalformedRegistryCache
     */
    public function decode(array $files, string $directory): CompiledRegistry
    {
        $entries = [];
        $first = null;

        foreach (RegistryName::cases() as $name) {
            $path = $directory.'/'.$name->fileName();
            $file = $files[$name->value] ?? null;

            // Checked before the keys, so a cache of another format says so instead of naming keys.
            if (is_array($file) && array_key_exists('format', $file) && $file['format'] !== self::FORMAT) {
                throw MalformedRegistryCache::at($path, 'format', sprintf('format %s is not format %d, which this version of the core reads', $this->show($file['format']), self::FORMAT));
            }

            $data = $this->map($file, $path, '', ['build', 'entries', 'format', 'registry']);

            if ($data['registry'] !== $name->value) {
                throw MalformedRegistryCache::at($path, 'registry', sprintf('it names the registry %s, not "%s"', $this->show($data['registry']), $name->value));
            }

            $build = $this->string($data['build'], $path, 'build');

            if (preg_match('/\A[0-9a-f]{64}\z/', $build) !== 1) {
                throw MalformedRegistryCache::at($path, 'build', sprintf('"%s" is not a sha256 in lowercase hex', $build));
            }

            if ($first === null) {
                $first = [$name, $build];
            } elseif ($build !== $first[1]) {
                throw MalformedRegistryCache::at($path, 'build', sprintf(
                    'it comes from another cms:build than %s: the files were read while a build replaced them, or a build stopped before it had replaced them all',
                    $first[0]->fileName(),
                ));
            }

            $entries[$name->value] = $this->list($data['entries'], $path, 'entries');
        }

        $actions = [];
        $commands = [];
        $hooks = [];

        foreach ($entries[RegistryName::Actions->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Actions->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['class', 'command', 'command_class', 'command_version', 'kind', 'package', 'surfaces']);
            $kind = $this->string($data['kind'], $path, $at.'.kind');
            $command = $this->commandName($data['command'], $path, $at.'.command');
            $surfaces = [];

            foreach ($this->list($data['surfaces'], $path, $at.'.surfaces') as $position => $surface) {
                $value = $this->string($surface, $path, sprintf('%s.surfaces[%d]', $at, $position));
                $surfaces[] = Surface::tryFrom($value) ?? throw MalformedRegistryCache::at($path, sprintf('%s.surfaces[%d]', $at, $position), sprintf('"%s" is not a surface', $value));
            }

            $actions[] = $this->entry($path, $at, fn (): ActionEntry => new ActionEntry(
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
                ActionKind::tryFrom($kind) ?? throw MalformedRegistryCache::at($path, $at.'.kind', sprintf('"%s" is not an action kind', $kind)),
                $command,
                $this->int($data['command_version'], $path, $at.'.command_version'),
                $this->string($data['command_class'], $path, $at.'.command_class'),
                $surfaces,
            ));
        }

        foreach ($entries[RegistryName::Commands->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Commands->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['class', 'name', 'package', 'version']);
            $name = $this->commandName($data['name'], $path, $at.'.name');

            $commands[] = $this->entry($path, $at, fn (): CommandEntry => new CommandEntry(
                $name,
                $this->int($data['version'], $path, $at.'.version'),
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
            ));
        }

        foreach ($entries[RegistryName::Hooks->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Hooks->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['addon', 'budget_ms', 'class', 'command', 'command_class', 'command_version', 'package', 'phase', 'priority', 'reads']);
            $phase = $this->string($data['phase'], $path, $at.'.phase');
            $command = $this->commandName($data['command'], $path, $at.'.command');
            $addon = $data['addon'] === null ? null : $this->addonNamespace($data['addon'], $path, $at.'.addon');
            $reads = $data['reads'] === null ? null : $this->string($data['reads'], $path, $at.'.reads');

            $hooks[] = $this->entry($path, $at, fn (): HookEntry => new HookEntry(
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
                $command,
                $this->int($data['command_version'], $path, $at.'.command_version'),
                $this->string($data['command_class'], $path, $at.'.command_class'),
                Phase::tryFrom($phase) ?? throw MalformedRegistryCache::at($path, $at.'.phase', sprintf('"%s" is not a hook phase', $phase)),
                $this->int($data['priority'], $path, $at.'.priority'),
                $this->int($data['budget_ms'], $path, $at.'.budget_ms'),
                $addon,
                $reads === null ? null : ClassificationAccess::tryFrom($reads) ?? throw MalformedRegistryCache::at($path, $at.'.reads', sprintf('"%s" is not a classification', $reads)),
            ));
        }

        $rest = [];

        foreach ($entries[RegistryName::Rest->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Rest->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['kind', 'method', 'name', 'path', 'version']);
            $kind = $this->string($data['kind'], $path, $at.'.kind');
            $name = $this->commandName($data['name'], $path, $at.'.name');
            $route = $this->entry($path, $at, fn (): RestRoute => new RestRoute(
                ActionKind::tryFrom($kind) ?? throw MalformedRegistryCache::at($path, $at.'.kind', sprintf('"%s" is not an action kind', $kind)),
                $name,
                $this->int($data['version'], $path, $at.'.version'),
            ));

            foreach (['method' => $route->method->value, 'path' => $route->path] as $key => $expected) {
                $held = $this->string($data[$key], $path, $at.'.'.$key);

                if ($held !== $expected) {
                    throw MalformedRegistryCache::at($path, $at.'.'.$key, sprintf('"%s" is not the %s of the route of %s version %d, "%s"', $held, $key, $route->name->value, $route->version, $expected));
                }
            }

            $rest[] = $route;
        }

        $schema = [];

        foreach ($entries[RegistryName::Schema->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Schema->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['extends', 'field_type_contributor', 'field_types', 'namespace', 'package', 'types']);
            $namespace = $this->addonNamespace($data['namespace'], $path, $at.'.namespace');
            $fieldTypes = [];

            foreach ($this->list($data['field_types'], $path, $at.'.field_types') as $position => $fieldType) {
                $fieldTypes[] = $this->fieldType($fieldType, $path, sprintf('%s.field_types[%d]', $at, $position));
            }

            $types = $this->typeNames($data['types'], $path, $at.'.types');
            $extends = $this->typeNames($data['extends'], $path, $at.'.extends');
            $contributor = $data['field_type_contributor'] === null ? null : $this->string($data['field_type_contributor'], $path, $at.'.field_type_contributor');

            $schema[] = $this->entry($path, $at, fn (): SchemaEntry => new SchemaEntry(
                $namespace,
                $this->string($data['package'], $path, $at.'.package'),
                $fieldTypes,
                $types,
                $extends,
                $contributor,
            ));
        }

        $subscribers = [];

        foreach ($entries[RegistryName::Subscribers->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Subscribers->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['addon', 'class', 'events', 'lane', 'name', 'package', 'projection']);
            $addon = $data['addon'] === null ? null : $this->addonNamespace($data['addon'], $path, $at.'.addon');
            $lane = $this->string($data['lane'], $path, $at.'.lane');
            $name = $this->subscriptionName($data['name'], $path, $at.'.name');
            $projection = $data['projection'] === null ? null : $this->projectionName($data['projection'], $path, $at.'.projection');
            $events = [];

            foreach ($this->list($data['events'], $path, $at.'.events') as $position => $event) {
                $eventAt = sprintf('%s.events[%d]', $at, $position);
                $eventData = $this->map($event, $path, $eventAt, ['class', 'name', 'version']);
                $type = $this->eventType($this->string($eventData['name'], $path, $eventAt.'.name'), $this->int($eventData['version'], $path, $eventAt.'.version'), $path, $eventAt);
                $events[] = $this->entry($path, $eventAt, fn (): SubscribedEvent => new SubscribedEvent($this->string($eventData['class'], $path, $eventAt.'.class'), $type));
            }

            $subscribers[] = $this->entry($path, $at, fn (): SubscriberEntry => new SubscriberEntry(
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
                $name,
                Lane::tryFrom($lane) ?? throw MalformedRegistryCache::at($path, $at.'.lane', sprintf('"%s" is not a lane', $lane)),
                $projection,
                $events,
                $addon,
            ));
        }

        $panel = [];

        foreach ($entries[RegistryName::Panel->value] as $index => $entry) {
            $panel[] = $this->panelPoint($entry, $directory.'/'.RegistryName::Panel->fileName(), sprintf('entries[%d]', $index));
        }

        $registry = new CompiledRegistry($commands, $hooks, $actions, $subscribers, $schema, $rest, $panel);

        if ($this->build($this->entries($registry)) !== $first[1]) {
            throw MalformedRegistryCache::at($directory.'/'.$first[0]->fileName(), 'build', 'the build does not match the entries of the registry files, so they were changed after cms:build wrote them');
        }

        return $registry;
    }

    /**
     * @throws MalformedRegistryCache
     */
    private function panelPoint(mixed $entry, string $path, string $at): PanelPointEntry
    {
        $data = $this->map($entry, $path, $at, ['class', 'fills', 'id', 'keyed_by', 'kind', 'label', 'max', 'multiplicity', 'ownership', 'package', 'page', 'region', 'since', 'stability', 'tightens']);
        $id = $this->panel($path, $at.'.id', fn (): PointId => PointId::fromString($this->string($data['id'], $path, $at.'.id')));
        $kind = $this->enum(PointKind::class, $data['kind'], $path, $at.'.kind', 'panel point kind');
        $multiplicity = $this->enum(Multiplicity::class, $data['multiplicity'], $path, $at.'.multiplicity', 'multiplicity');
        $stability = $this->enum(PointStability::class, $data['stability'], $path, $at.'.stability', 'stability');
        $region = $data['region'] === null ? null : $this->enum(Region::class, $data['region'], $path, $at.'.region', 'region');
        $ownership = $data['ownership'] === null ? null : $this->enum(Ownership::class, $data['ownership'], $path, $at.'.ownership', 'ownership');
        $keyedBy = $data['keyed_by'] === null ? null : $this->enum(ReplacementKey::class, $data['keyed_by'], $path, $at.'.keyed_by', 'replacement key');
        $max = $data['max'] === null ? null : $this->int($data['max'], $path, $at.'.max');
        $tightens = [];

        foreach ($this->list($data['tightens'], $path, $at.'.tightens') as $position => $tighten) {
            $tightens[] = $this->enum(Tighten::class, $tighten, $path, sprintf('%s.tightens[%d]', $at, $position), 'tightening prop');
        }

        $declaration = $this->panel($path, $at, fn (): PanelPoint => new PanelPoint(
            $id->name->value,
            $id->version,
            $kind,
            $this->string($data['page'], $path, $at.'.page'),
            $this->string($data['since'], $path, $at.'.since'),
            $this->string($data['label'], $path, $at.'.label'),
            $region,
            $multiplicity,
            $max,
            $ownership,
            $keyedBy,
            $tightens,
        ));
        $fills = [];

        foreach ($this->list($data['fills'], $path, $at.'.fills') as $position => $fill) {
            $fills[] = $this->panelFill($fill, $path, sprintf('%s.fills[%d]', $at, $position));
        }

        return $this->entry($path, $at, fn (): PanelPointEntry => new PanelPointEntry(
            $declaration,
            $this->string($data['class'], $path, $at.'.class'),
            $this->string($data['package'], $path, $at.'.package'),
            $stability,
            $fills,
        ));
    }

    /**
     * @throws MalformedRegistryCache
     */
    private function panelFill(mixed $fill, string $path, string $at): PanelFill
    {
        $data = $this->map($fill, $path, $at, ['contribution', 'package', 'priority', 'scope']);
        $scope = $this->map($data['scope'], $path, $at.'.scope', ['commands', 'field_types', 'pages', 'requires', 'types']);
        $contribution = $this->panel($path, $at.'.contribution', fn (): ContributionId => new ContributionId($this->string($data['contribution'], $path, $at.'.contribution')));
        $commands = [];
        $pages = [];
        $fieldTypes = [];

        foreach ($this->list($scope['commands'], $path, $at.'.scope.commands') as $position => $command) {
            $commandAt = sprintf('%s.scope.commands[%d]', $at, $position);
            $commands[] = $this->panel($path, $commandAt, fn (): CommandRef => CommandRef::fromString($this->string($command, $path, $commandAt)));
        }

        foreach ($this->list($scope['pages'], $path, $at.'.scope.pages') as $position => $page) {
            $pageAt = sprintf('%s.scope.pages[%d]', $at, $position);
            $pages[] = $this->panel($path, $pageAt, fn (): PageName => new PageName($this->string($page, $path, $pageAt)));
        }

        foreach ($this->list($scope['field_types'], $path, $at.'.scope.field_types') as $position => $fieldType) {
            $fieldTypes[] = $this->string($fieldType, $path, sprintf('%s.scope.field_types[%d]', $at, $position));
        }

        $types = $this->typeNames($scope['types'], $path, $at.'.scope.types');
        $requires = $scope['requires'] === null ? null : $this->commandName($scope['requires'], $path, $at.'.scope.requires');
        $priority = $this->int($data['priority'], $path, $at.'.priority');

        return $this->entry($path, $at, fn (): PanelFill => new PanelFill(
            $contribution,
            $this->string($data['package'], $path, $at.'.package'),
            $priority,
            $this->panel($path, $at.'.scope', static fn (): Scope => new Scope($pages, $commands, $types, $fieldTypes, $requires)),
        ));
    }

    /**
     * A value of the panel registry, whose value objects refuse a value with InvalidPanelPoint.
     *
     * @template T of object
     *
     * @param  callable(): T  $build
     * @return T
     *
     * @throws MalformedRegistryCache
     */
    private function panel(string $path, string $at, callable $build): object
    {
        try {
            return $build();
        } catch (InvalidPanelPoint $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    /**
     * The case of a string-backed enum that a string of the cache names.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     *
     * @throws MalformedRegistryCache
     */
    private function enum(string $enum, mixed $value, string $path, string $at, string $what): object
    {
        $text = $this->string($value, $path, $at);

        return $enum::tryFrom($text) ?? throw MalformedRegistryCache::at($path, $at, sprintf('"%s" is not a %s', $text, $what));
    }

    /**
     * PHP for a value of the cache: null, a boolean, an integer, a string, or an array of them.
     * Arrays keep their order; encode() builds them with their keys in alphabetical order.
     */
    private function emit(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value) => (string) $value,
                is_string($value) => var_export($value, true),
                default => throw new LogicException(sprintf('The registry cache cannot hold a %s.', get_debug_type($value))),
            };
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $lines[] = $indent.$this->emit($item, $depth + 1).',';
            }
        } else {
            foreach ($value as $key => $item) {
                $lines[] = $indent.var_export((string) $key, true).' => '.$this->emit($item, $depth + 1).',';
            }
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    /**
     * @template T of object
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function entry(string $path, string $at, callable $build): object
    {
        try {
            return $build();
        } catch (InvalidRegistryEntry $invalid) {
            throw MalformedRegistryCache::at($path, $at, $invalid->getMessage(), $invalid);
        }
    }

    /**
     * @param  list<string>  $keys  sorted
     * @return array<string, mixed>
     */
    private function map(mixed $value, string $path, string $at, array $keys): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw MalformedRegistryCache::at($path, $at, sprintf('expected an array with the keys %s, got %s', implode(', ', $keys), get_debug_type($value)));
        }

        $actual = array_map(strval(...), array_keys($value));
        sort($actual, SORT_STRING);

        if ($actual !== $keys) {
            throw MalformedRegistryCache::at($path, $at, sprintf('expected the keys %s, got %s', implode(', ', $keys), $actual === [] ? 'none' : implode(', ', $actual)));
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $path, string $at): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw MalformedRegistryCache::at($path, $at, sprintf('expected a list, got %s', get_debug_type($value)));
        }

        return $value;
    }

    private function string(mixed $value, string $path, string $at): string
    {
        if (! is_string($value)) {
            throw MalformedRegistryCache::at($path, $at, sprintf('expected a string, got %s', get_debug_type($value)));
        }

        return $value;
    }

    private function commandName(mixed $value, string $path, string $at): CommandName
    {
        $name = $this->string($value, $path, $at);

        try {
            return new CommandName($name);
        } catch (InvalidCommandName $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    private function subscriptionName(mixed $value, string $path, string $at): SubscriptionName
    {
        $name = $this->string($value, $path, $at);

        try {
            return new SubscriptionName($name);
        } catch (InvalidSubscriptionName $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    private function projectionName(mixed $value, string $path, string $at): ProjectionName
    {
        $name = $this->string($value, $path, $at);

        try {
            return new ProjectionName($name);
        } catch (InvalidReceipt $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    private function addonNamespace(mixed $value, string $path, string $at): AddonNamespace
    {
        $namespace = $this->string($value, $path, $at);

        try {
            return new AddonNamespace($namespace);
        } catch (InvalidAddonManifest|ReservedAddonNamespace $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    private function fieldType(mixed $value, string $path, string $at): ContributedFieldType
    {
        $name = $this->string($value, $path, $at);

        try {
            return new ContributedFieldType($name);
        } catch (InvalidAddonManifest $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    /**
     * @return list<TypeName>
     */
    private function typeNames(mixed $value, string $path, string $at): array
    {
        $types = [];

        foreach ($this->list($value, $path, $at) as $position => $type) {
            $name = $this->string($type, $path, sprintf('%s[%d]', $at, $position));

            try {
                $types[] = new TypeName($name);
            } catch (InvalidTypeDefinition $invalid) {
                throw MalformedRegistryCache::at($path, sprintf('%s[%d]', $at, $position), rtrim($invalid->getMessage(), '.'), $invalid);
            }
        }

        return $types;
    }

    private function eventType(string $name, int $version, string $path, string $at): EventType
    {
        try {
            return new EventType($name, $version);
        } catch (InvalidEvent $invalid) {
            throw MalformedRegistryCache::at($path, $at, rtrim($invalid->getMessage(), '.'), $invalid);
        }
    }

    private function int(mixed $value, string $path, string $at): int
    {
        if (! is_int($value)) {
            throw MalformedRegistryCache::at($path, $at, sprintf('expected an integer, got %s', get_debug_type($value)));
        }

        return $value;
    }

    private function show(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use LogicException;

/**
 * The registry cache files of format 1, in both directions (PRD 13.2).
 *
 * A file is PHP that returns ['entries' => [...], 'format' => 1, 'registry' => '<name>']. The keys
 * of every array are written in alphabetical order, lists keep the compiled order, and nothing
 * depends on the time or the machine, so the same registry always gives the same bytes. Reading checks every
 * key and type and builds the typed entries; anything else is MalformedRegistryCache.
 */
#[Internal]
final readonly class RegistryCacheCodec
{
    public const int FORMAT = 1;

    private const string HEADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // Written by php artisan cms:build from the attributes in the declared scan roots (PRD 13.2).
        // Do not edit and do not commit; run cms:build again instead.

        PHP;

    /**
     * @return array<string, string> the PHP source of each file, keyed by registry name
     */
    public function encode(CompiledRegistry $registry): array
    {
        $entries = [
            RegistryName::Actions->value => array_map(static fn (ActionEntry $action): array => [
                'class' => $action->class,
                'package' => $action->package,
                'surfaces' => array_map(static fn (Surface $surface): string => $surface->value, $action->surfaces),
            ], $registry->actions),
            RegistryName::Commands->value => array_map(static fn (CommandEntry $command): array => [
                'class' => $command->class,
                'name' => $command->name,
                'package' => $command->package,
                'version' => $command->version,
            ], $registry->commands),
            RegistryName::Hooks->value => array_map(static fn (HookEntry $hook): array => [
                'budget_ms' => $hook->budgetMs,
                'class' => $hook->class,
                'command' => $hook->command,
                'command_class' => $hook->commandClass,
                'command_version' => $hook->commandVersion,
                'package' => $hook->package,
                'phase' => $hook->phase->value,
                'priority' => $hook->priority,
            ], $registry->hooks),
        ];

        $files = [];

        foreach (RegistryName::cases() as $name) {
            $files[$name->value] = self::HEADER."\nreturn ".$this->emit([
                'entries' => $entries[$name->value],
                'format' => self::FORMAT,
                'registry' => $name->value,
            ], 0).";\n";
        }

        return $files;
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

        foreach (RegistryName::cases() as $name) {
            $path = $directory.'/'.$name->fileName();
            $file = $files[$name->value] ?? null;
            $data = $this->map($file, $path, '', ['entries', 'format', 'registry']);

            if ($data['format'] !== self::FORMAT) {
                throw MalformedRegistryCache::at($path, 'format', sprintf('format %s is not format %d, which this version of the core reads', $this->show($data['format']), self::FORMAT));
            }

            if ($data['registry'] !== $name->value) {
                throw MalformedRegistryCache::at($path, 'registry', sprintf('it names the registry %s, not "%s"', $this->show($data['registry']), $name->value));
            }

            $entries[$name->value] = $this->list($data['entries'], $path, 'entries');
        }

        $actions = [];
        $commands = [];
        $hooks = [];

        foreach ($entries[RegistryName::Actions->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Actions->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['class', 'package', 'surfaces']);
            $surfaces = [];

            foreach ($this->list($data['surfaces'], $path, $at.'.surfaces') as $position => $surface) {
                $surfaces[] = Surface::tryFrom($this->string($surface, $path, sprintf('%s.surfaces[%d]', $at, $position)))
                    ?? throw MalformedRegistryCache::at($path, sprintf('%s.surfaces[%d]', $at, $position), sprintf('%s is not a surface', $this->show($surface)));
            }

            $actions[] = $this->entry($path, $at, fn (): ActionEntry => new ActionEntry(
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
                $surfaces,
            ));
        }

        foreach ($entries[RegistryName::Commands->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Commands->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['class', 'name', 'package', 'version']);

            $commands[] = $this->entry($path, $at, fn (): CommandEntry => new CommandEntry(
                $this->string($data['name'], $path, $at.'.name'),
                $this->int($data['version'], $path, $at.'.version'),
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
            ));
        }

        foreach ($entries[RegistryName::Hooks->value] as $index => $entry) {
            $path = $directory.'/'.RegistryName::Hooks->fileName();
            $at = sprintf('entries[%d]', $index);
            $data = $this->map($entry, $path, $at, ['budget_ms', 'class', 'command', 'command_class', 'command_version', 'package', 'phase', 'priority']);
            $phase = $this->string($data['phase'], $path, $at.'.phase');

            $hooks[] = $this->entry($path, $at, fn (): HookEntry => new HookEntry(
                $this->string($data['class'], $path, $at.'.class'),
                $this->string($data['package'], $path, $at.'.package'),
                $this->string($data['command'], $path, $at.'.command'),
                $this->int($data['command_version'], $path, $at.'.command_version'),
                $this->string($data['command_class'], $path, $at.'.command_class'),
                Phase::tryFrom($phase) ?? throw MalformedRegistryCache::at($path, $at.'.phase', sprintf('"%s" is not a hook phase', $phase)),
                $this->int($data['priority'], $path, $at.'.priority'),
                $this->int($data['budget_ms'], $path, $at.'.budget_ms'),
            ));
        }

        return new CompiledRegistry($actions, $commands, $hooks);
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

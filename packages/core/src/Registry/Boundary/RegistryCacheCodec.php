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
 * The registry cache files of format 2, in both directions (PRD 13.2).
 *
 * A file is PHP that returns ['build' => '<sha256>', 'entries' => [...], 'format' => 2,
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
    public const int FORMAT = 2;

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

        $registry = new CompiledRegistry($actions, $commands, $hooks);

        if ($this->build($this->entries($registry)) !== $first[1]) {
            throw MalformedRegistryCache::at($directory.'/'.$first[0]->fileName(), 'build', 'the build does not match the entries of the registry files, so they were changed after cms:build wrote them');
        }

        return $registry;
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

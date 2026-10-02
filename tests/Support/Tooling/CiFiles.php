<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the CI files of the monorepo: the workflow, the compose files and the entry scripts, so
 * the tests can hold them to each other and to the local profile.
 */
final readonly class CiFiles
{
    public const string WORKFLOW = '.github/workflows/ci.yml';

    public const string COMPOSE_CI = 'compose.ci.yaml';

    public const string COMPOSE = 'compose.yaml';

    public const string DOCKERFILE = 'docker/ci.Dockerfile';

    public const string ENTRY = 'bin/ci';

    public static function text(string $file): string
    {
        $text = file_get_contents(Phpstan::root().'/'.$file);

        if ($text === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        return $text;
    }

    /**
     * A YAML file as nested arrays; custom tags such as compose's !reset stay TaggedValue objects.
     *
     * @return array<array-key, mixed>
     */
    public static function yaml(string $file): array
    {
        $parsed = Yaml::parse(self::text($file), Yaml::PARSE_CUSTOM_TAGS);

        if (! is_array($parsed)) {
            throw new RuntimeException("{$file} is not a YAML mapping.");
        }

        return $parsed;
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function at(array $data, string ...$keys): mixed
    {
        $value = $data;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A mapping of strings at a path, such as a service's environment.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public static function strings(array $data, string ...$keys): array
    {
        $value = self::at($data, ...$keys);

        if (! is_array($value)) {
            throw new RuntimeException('No mapping at '.implode('.', $keys).'.');
        }

        $strings = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! (is_string($item) || is_int($item) || is_bool($item))) {
                throw new RuntimeException('Not a mapping of strings at '.implode('.', $keys).'.');
            }

            $strings[$key] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
        }

        return $strings;
    }

    /**
     * The Composer and npm scripts gates 1 to 6 of the local profile run, in order.
     *
     * @return list<string>
     */
    public static function profileScripts(): array
    {
        $composer = ['/usr/bin/php', '/usr/bin/composer'];
        $scripts = [];

        foreach (LocalProfile::gates('/usr/bin/php', $composer) as $gate) {
            foreach (self::runningCommands($gate) as $command) {
                if (array_slice($command, 0, 2) === ['npm', 'run']) {
                    $scripts[] = $command[2] ?? throw new RuntimeException('npm run without a script.');
                } elseif (array_slice($command, 0, 2) === $composer) {
                    $scripts[] = $command[2] ?? throw new RuntimeException('composer without a script.');
                }
            }
        }

        return $scripts;
    }

    /**
     * The one step of a job whose key ($key, run or uses) starts with $prefix; an empty array
     * when there is none.
     *
     * @param  list<array<array-key, mixed>>  $steps
     * @return array<array-key, mixed>
     */
    public static function stepWith(array $steps, string $key, string $prefix): array
    {
        $found = array_values(array_filter($steps, static fn (array $step): bool => is_string($step[$key] ?? null) && str_starts_with($step[$key], $prefix)));

        return count($found) === 1 ? $found[0] : [];
    }

    /**
     * The lines of a shell script without comments and blank lines.
     *
     * @return list<string>
     */
    public static function codeLines(string $file): array
    {
        $lines = array_map(trim(...), explode("\n", self::text($file)));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    /**
     * @return list<list<string>>
     */
    private static function runningCommands(Gate $gate): array
    {
        $commands = [];

        foreach ($gate->steps as $step) {
            if ($step->runs()) {
                $commands[] = $step->command;
            }
        }

        return $commands;
    }
}

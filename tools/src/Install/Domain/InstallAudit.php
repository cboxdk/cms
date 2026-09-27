<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Install\Domain;

/**
 * Whether the installation in vendor/ is the one composer.lock and composer.json describe. The
 * gates run the code through Composer's autoloader and against the installed packages, so a
 * checkout whose vendor/ is older than its composer.json or composer.lock checks other code than
 * CI, which installs from the lock file: a PSR-4 prefix composer.json gained is missing, and a
 * test that extends a class below it fails its suite at load with "Class not found".
 *
 * The packages must match composer.lock by name, version and reference, and the root package's
 * PSR-4, PSR-0 and files rules in composer.json's autoload and autoload-dev must match what the
 * dumped autoloader maps below the root. `composer install` brings both up to date.
 */
final readonly class InstallAudit
{
    public const string FIX = 'Run composer install, which installs composer.lock and dumps the autoloader with autoload-dev.';

    /**
     * @return list<string> one line per difference, empty when the installation is current
     */
    public static function problems(ComposerState $declared, ComposerState $installed): array
    {
        return [
            ...self::packageProblems($declared->packages, $installed->packages),
            ...self::autoloadProblems($declared->rootAutoload, $installed->rootAutoload),
        ];
    }

    /**
     * @param  array<string, string>  $locked
     * @param  array<string, string>  $installed
     * @return list<string>
     */
    private static function packageProblems(array $locked, array $installed): array
    {
        $problems = [];

        foreach ($locked as $name => $version) {
            $problems[] = match (true) {
                ! array_key_exists($name, $installed) => "{$name} {$version} is in composer.lock, but not installed in vendor/.",
                $installed[$name] !== $version => "{$name} is installed at {$installed[$name]}, but composer.lock has {$version}.",
                default => null,
            };
        }

        foreach ($installed as $name => $version) {
            if (! array_key_exists($name, $locked)) {
                $problems[] = "{$name} {$version} is installed in vendor/, but composer.lock does not list it.";
            }
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * @param  array<string, list<string>>  $declared
     * @param  array<string, list<string>>  $dumped
     * @return list<string>
     */
    private static function autoloadProblems(array $declared, array $dumped): array
    {
        $problems = [];
        $rules = array_unique([...array_keys($declared), ...array_keys($dumped)]);
        sort($rules);

        foreach ($rules as $rule) {
            $wanted = $declared[$rule] ?? [];
            $actual = $dumped[$rule] ?? [];

            $problems[] = match (true) {
                $wanted === $actual => null,
                $actual === [] => "composer.json autoloads {$rule} from ".self::paths($wanted).', but the dumped autoloader in vendor/composer does not.',
                $wanted === [] => "The dumped autoloader in vendor/composer autoloads {$rule} from ".self::paths($actual).', but composer.json no longer does.',
                default => "composer.json autoloads {$rule} from ".self::paths($wanted).', but the dumped autoloader in vendor/composer from '.self::paths($actual).'.',
            };
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * @param  list<string>  $paths
     */
    private static function paths(array $paths): string
    {
        return implode(', ', array_map(static fn (string $path): string => $path === '' ? '.' : $path, $paths));
    }
}

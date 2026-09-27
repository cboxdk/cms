<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Install\Boundary;

use Cbox\Cms\Tooling\Install\Domain\ComposerState;
use JsonException;
use UnexpectedValueException;

/**
 * Reads both sides of a checkout's Composer installation for InstallAudit: what composer.json and
 * composer.lock declare, and what Composer last wrote to vendor/composer (installed.json and the
 * dumped autoload_psr4.php, autoload_namespaces.php and autoload_files.php). Only the root
 * package's autoload rules are compared: paths below vendor/ belong to installed packages, which
 * the package comparison covers.
 */
final readonly class ComposerInstallation
{
    /**
     * The autoload kinds keyed by prefix, and the dumped file that maps each.
     *
     * @var array<string, string>
     */
    private const array PREFIXED = ['psr-4' => 'autoload_psr4.php', 'psr-0' => 'autoload_namespaces.php'];

    private function __construct(private string $root) {}

    /**
     * @throws UnexpectedValueException when the root is not a directory
     */
    public static function at(string $root): self
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new UnexpectedValueException("{$root} is not a directory.");
        }

        return new self($real);
    }

    /**
     * What composer.lock and composer.json declare.
     *
     * @throws UnexpectedValueException when either file is missing or not the JSON Composer writes
     */
    public function declared(): ComposerState
    {
        $lock = $this->json('composer.lock');
        $composer = $this->json('composer.json');
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($this->list($lock[$section] ?? [], "composer.lock {$section}") as $package) {
                [$name, $version] = $this->package($package, 'composer.lock');
                $packages[$name] = $version;
            }
        }

        $rules = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            if (! is_array($autoload)) {
                throw new UnexpectedValueException("composer.json {$section} is not an object.");
            }

            foreach (array_keys(self::PREFIXED) as $kind) {
                $prefixes = $autoload[$kind] ?? [];

                if (! is_array($prefixes)) {
                    throw new UnexpectedValueException("composer.json {$section}.{$kind} is not an object.");
                }

                foreach ($prefixes as $prefix => $paths) {
                    foreach (is_array($paths) ? $paths : [$paths] as $path) {
                        if (! is_string($path)) {
                            throw new UnexpectedValueException("composer.json {$section}.{$kind} maps {$prefix} to a path that is not a string.");
                        }

                        $rules["{$kind} {$prefix}"][] = $this->declaredPath($path);
                    }
                }
            }

            foreach ($this->list($autoload['files'] ?? [], "composer.json {$section}.files") as $path) {
                if (! is_string($path)) {
                    throw new UnexpectedValueException("composer.json {$section}.files has a path that is not a string.");
                }

                $rules['files'][] = $this->declaredPath($path);
            }
        }

        ksort($packages);

        return new ComposerState($packages, $this->sorted($rules));
    }

    /**
     * What `composer install` and `composer dump-autoload` last wrote to vendor/composer.
     *
     * @throws UnexpectedValueException when vendor/composer has no installed.json or dumped autoloader
     */
    public function installed(): ComposerState
    {
        $installed = $this->json('vendor/composer/installed.json');
        $packages = [];

        foreach ($this->list($installed['packages'] ?? null, 'vendor/composer/installed.json packages') as $package) {
            [$name, $version] = $this->package($package, 'vendor/composer/installed.json');
            $packages[$name] = $version;
        }

        $rules = [];

        foreach (self::PREFIXED as $kind => $file) {
            $map = $this->dumped($file, true);

            foreach ($map as $prefix => $paths) {
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    $relative = is_string($path) ? $this->belowRoot($path) : null;

                    if ($relative !== null) {
                        $rules["{$kind} {$prefix}"][] = $relative;
                    }
                }
            }
        }

        foreach ($this->dumped('autoload_files.php', false) as $path) {
            $relative = is_string($path) ? $this->belowRoot($path) : null;

            if ($relative !== null) {
                $rules['files'][] = $relative;
            }
        }

        ksort($packages);

        return new ComposerState($packages, $this->sorted($rules));
    }

    /**
     * The path of a file below the root and outside vendor/, relative to the root without a
     * trailing slash, or null for a path of an installed package or outside the root.
     */
    private function belowRoot(string $path): ?string
    {
        $path = rtrim($path, '/');

        if ($path === $this->root) {
            return '';
        }

        if (! str_starts_with($path, $this->root.'/') || str_starts_with($path, $this->root.'/vendor/') || $path === $this->root.'/vendor') {
            return null;
        }

        return substr($path, strlen($this->root) + 1);
    }

    /**
     * A path from composer.json relative to the root, as the dumped autoloader writes it.
     */
    private function declaredPath(string $path): string
    {
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        $path = rtrim($path, '/');

        return $path === '.' ? '' : $path;
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function sorted(array $rules): array
    {
        foreach ($rules as $rule => $paths) {
            $paths = array_values(array_unique($paths));
            sort($paths);
            $rules[$rule] = $paths;
        }

        ksort($rules);

        return $rules;
    }

    /**
     * @return array{0: string, 1: string} the name, and the version with the reference
     */
    private function package(mixed $package, string $file): array
    {
        if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
            throw new UnexpectedValueException("{$file} has a package without a name and a version.");
        }

        $dist = $package['dist'] ?? null;
        $source = $package['source'] ?? null;
        $reference = match (true) {
            is_array($dist) && is_string($dist['reference'] ?? null) => $dist['reference'],
            is_array($source) && is_string($source['reference'] ?? null) => $source['reference'],
            default => null,
        };

        return [$package['name'], $reference === null ? $package['version'] : "{$package['version']}@{$reference}"];
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $what): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("{$what} is not a list.");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $file): array
    {
        $path = $this->root.'/'.$file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new UnexpectedValueException("Cannot read {$file}.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("{$file} is not valid JSON: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("{$file} is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * A map Composer dumped to vendor/composer, with absolute paths: the file computes them from
     * its own location.
     *
     * @return array<mixed>
     */
    private function dumped(string $file, bool $required): array
    {
        $path = $this->root.'/vendor/composer/'.$file;

        if (! is_file($path)) {
            if ($required) {
                throw new UnexpectedValueException("Cannot read vendor/composer/{$file}.");
            }

            return [];
        }

        $map = (static fn (string $file): mixed => require $file)($path);

        if (! is_array($map)) {
            throw new UnexpectedValueException("vendor/composer/{$file} does not return an array.");
        }

        return $map;
    }
}

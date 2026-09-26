<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use FilesystemIterator;
use JsonException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Finds the Composer packages that a package's src uses, so a test can check that each one is
 * declared in the package's composer.json. The monorepo installs everything, so a class from an
 * undeclared package still loads here; after the split it would not.
 *
 * A package counts as used when src names one of its classes, interfaces, traits or enums in
 * code: imports, type declarations, `new`, static calls, `instanceof`, `catch` and attributes. The
 * class is found through the autoloader and mapped to the installed package that holds the file.
 * A class from laravel/framework maps to the illuminate/* package the framework replaces, by its
 * directory, so a package can require illuminate/support instead of the whole framework. Composer's
 * runtime classes, such as Composer\InstalledVersions, live in the vendor directory itself and map
 * to the platform package composer-runtime-api, which a package requires to use them.
 */
final readonly class PackageDependencies
{
    /** The classes Composer generates into every vendor directory, which composer-runtime-api provides. */
    private const array COMPOSER_RUNTIME = [ClassLoader::class, InstalledVersions::class];

    /**
     * The packages src uses, sorted. PHP's own classes and the package's own classes are left
     * out. A class the autoloader cannot find is reported as `unresolved:<class>`.
     *
     * @param  string  $package  the directory name below packages/, such as "generators"
     * @return list<string>
     */
    public static function usedBy(string $package): array
    {
        $manifest = self::manifest($package);
        $own = $manifest['name'];
        $packages = [];

        foreach (self::classesUsedIn(Phpstan::root().'/packages/'.$package.'/src') as $class) {
            $owner = self::owner($class);

            if ($owner !== null && $owner !== $own) {
                $packages[$owner] = $owner;
            }
        }

        sort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * The packages src uses that composer.json neither requires nor suggests, sorted.
     *
     * @param  array{name: string, require: array<string, string>, suggest: array<string, string>}|null  $manifest  the manifest to check against; null reads the package's composer.json
     * @return list<string>
     */
    public static function undeclared(string $package, ?array $manifest = null): array
    {
        $manifest ??= self::manifest($package);
        $declared = [...array_keys($manifest['require']), ...array_keys($manifest['suggest'])];

        return array_values(array_diff(self::usedBy($package), $declared));
    }

    /**
     * @return array{name: string, require: array<string, string>, suggest: array<string, string>}
     *
     * @throws JsonException
     */
    public static function manifest(string $package): array
    {
        $path = Phpstan::root().'/packages/'.$package.'/composer.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_string($data['name'] ?? null)) {
            throw new RuntimeException("{$path} has no package name.");
        }

        return [
            'name' => $data['name'],
            'require' => self::stringMap($data['require'] ?? []),
            'suggest' => self::stringMap($data['suggest'] ?? []),
        ];
    }

    /**
     * Every fully qualified class-like name used in code in the PHP files below the directory.
     *
     * @return list<string>
     */
    public static function classesUsedIn(string $directory): array
    {
        $parser = new ParserFactory()->createForHostVersion();
        $names = [];

        foreach (self::phpFiles($directory) as $file) {
            $statements = $parser->parse((string) file_get_contents($file)) ?? [];

            $traverser = new NodeTraverser(new NameResolver);
            $statements = $traverser->traverse($statements);

            foreach (new NodeFinder()->findInstanceOf($statements, Node\Name\FullyQualified::class) as $name) {
                $class = $name->toString();

                if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class) || (! function_exists($class) && ! defined($class))) {
                    $names[$class] = $class;
                }
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The package that holds the class, null for a class built into PHP.
     */
    private static function owner(string $class): ?string
    {
        if (in_array($class, self::COMPOSER_RUNTIME, true)) {
            return 'composer-runtime-api';
        }

        if ((class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) && new ReflectionClass($class)->isInternal()) {
            return null;
        }

        $file = null;

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $found = $loader->findFile($class);

            if (is_string($found)) {
                $file = realpath($found);

                break;
            }
        }

        if (! is_string($file)) {
            return 'unresolved:'.$class;
        }

        $owner = null;
        $longest = 0;

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            $path = InstalledVersions::getInstallPath($package);
            $path = $path === null ? false : realpath($path);

            if (is_string($path) && str_starts_with($file, $path.'/') && strlen($path) > $longest) {
                $owner = $package;
                $longest = strlen($path);
            }
        }

        if ($owner === 'laravel/framework') {
            return self::illuminatePackage($class) ?? $owner;
        }

        return $owner ?? 'unresolved:'.$class;
    }

    /**
     * The illuminate/* split package of a framework class, such as illuminate/console for
     * Illuminate\Console\Command, when laravel/framework replaces it.
     */
    private static function illuminatePackage(string $class): ?string
    {
        $segments = explode('\\', $class);

        if ($segments[0] !== 'Illuminate' || ! isset($segments[1])) {
            return null;
        }

        $package = 'illuminate/'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $segments[1]));
        $framework = InstalledVersions::getInstallPath('laravel/framework');
        $manifest = json_decode((string) file_get_contents($framework.'/composer.json'), true);
        $replaces = is_array($manifest) && is_array($manifest['replace'] ?? null) ? $manifest['replace'] : [];

        return array_key_exists($package, $replaces) ? $package : null;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }
}

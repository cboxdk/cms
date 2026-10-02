<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

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
 * The boundaries between the modules of the one package cboxdk/cms (GUARDRAILS 2.6, PRD 2.32).
 * The modules are namespaces with their code in packages/<module>/src, and nothing but these
 * rules keeps one module from using another or a package that composer.json only suggests:
 *
 * - contracts depends only on PHP: it uses no other module and no package;
 * - core, http, cli and mcp never use the testkit or the generators, and no module uses the tests,
 *   the tooling, the workbench or the examples;
 * - identity, the login (PRD 5.16), never uses the testkit or the generators, and core, http, cli
 *   and mcp never use identity: the actor aggregate and the actor commands are the core's, and
 *   identity builds on them, never the other way;
 * - panel, the PHP side of the control panel (PRD 13.4), may use core, http and identity besides the
 *   contracts, never cli, mcp, the testkit or the generators, and no module uses panel: the panel
 *   is a surface on top of the kernel, never something the kernel or another surface builds on;
 * - the testkit uses no module but contracts;
 * - every package a module uses is in composer.json's require or suggest, and the production
 *   modules (contracts, core, http, cli, mcp, identity, panel) use none that composer.json only suggests, because those
 *   are installed for development only and never reach production. The testkit is left out of
 *   this rule: it runs only in development, and its PHPStan rules name the classes of PHPStan, of
 *   nikic/php-parser inside PHPStan and of the clock and id libraries they report, not packages
 *   it depends on.
 *
 * A class counts as used when src names it in code: imports, type declarations, `new`, static
 * calls, `instanceof`, `catch` and attributes. A class of this package belongs to the module whose
 * namespace it is in. Any other class is found through the autoloader and mapped to the installed
 * package that holds its file; a class of laravel/framework maps to the illuminate/* package the
 * framework replaces, by its directory, so require can name illuminate/support instead of the whole
 * framework. Composer's runtime classes, such as Composer\InstalledVersions, live in the vendor
 * directory itself and map to the platform package composer-runtime-api.
 */
final readonly class ModuleDependencies
{
    /** The package the modules are part of. */
    public const string PACKAGE = 'cboxdk/cms';

    /**
     * Each module, its directory below packages/, with its namespace.
     *
     * @var array<string, string>
     */
    public const array MODULES = [
        'cli' => 'Cbox\Cms\Cli',
        'contracts' => 'Cbox\Cms\Contracts',
        'core' => 'Cbox\Cms\Core',
        'generators' => 'Cbox\Cms\Generators',
        'http' => 'Cbox\Cms\Http',
        'identity' => 'Cbox\Cms\Identity',
        'mcp' => 'Cbox\Cms\Mcp',
        'panel' => 'Cbox\Cms\Panel',
        'testkit' => 'Cbox\Cms\Testkit',
    ];

    /**
     * The modules an application runs in production. They may use only what composer.json requires.
     *
     * @var list<string>
     */
    public const array PRODUCTION = ['cli', 'contracts', 'core', 'http', 'identity', 'mcp', 'panel'];

    /**
     * The modules each module may not use.
     *
     * @var array<string, list<string>>
     */
    public const array FORBIDDEN_MODULES = [
        'cli' => ['generators', 'identity', 'panel', 'testkit'],
        'contracts' => ['cli', 'core', 'generators', 'http', 'identity', 'mcp', 'panel', 'testkit'],
        'core' => ['generators', 'identity', 'panel', 'testkit'],
        'generators' => ['panel', 'testkit'],
        'http' => ['generators', 'identity', 'panel', 'testkit'],
        'identity' => ['generators', 'panel', 'testkit'],
        'mcp' => ['generators', 'identity', 'panel', 'testkit'],
        'panel' => ['cli', 'generators', 'mcp', 'testkit'],
        'testkit' => ['cli', 'core', 'generators', 'http', 'identity', 'mcp', 'panel'],
    ];

    /**
     * Code of this repository outside the modules, which no module may use: its autoload-dev
     * namespaces.
     *
     * @var list<string>
     */
    public const array DEVELOPMENT_CODE = ['Cbox\Cms\Tests', 'Cbox\Cms\Tooling', 'Examples', 'Workbench\App'];

    /** The classes Composer generates into every vendor directory, which composer-runtime-api provides. */
    private const array COMPOSER_RUNTIME = [ClassLoader::class, InstalledVersions::class];

    /**
     * Every break of the module rules in the module's src, or in the directory given in its place,
     * one line per file and use: `<file>: <class> (<module or package>): <rule>`.
     *
     * @param  array{require: array<string, string>, suggest: array<string, string>}|null  $manifest  the manifest to check against; null reads composer.json
     * @return list<string>
     */
    public static function violations(string $module, ?string $directory = null, ?array $manifest = null): array
    {
        if (! array_key_exists($module, self::MODULES)) {
            throw new RuntimeException("{$module} is not a module of ".self::PACKAGE.'.');
        }

        $manifest ??= self::manifest();
        $directory ??= Codebase::root().'/packages/'.$module.'/src';
        $production = in_array($module, self::PRODUCTION, true);
        $violations = [];

        foreach (self::classesUsedByFile($directory) as $file => $classes) {
            $file = Codebase::relative($file);

            foreach ($classes as $class) {
                $owner = self::moduleOf($class);

                if ($owner !== null) {
                    if (in_array($owner, self::FORBIDDEN_MODULES[$module], true)) {
                        $violations[] = "{$file}: {$class} (module {$owner}): {$module} may not use ".implode(', ', self::FORBIDDEN_MODULES[$module]).'.';
                    }

                    continue;
                }

                if (self::isDevelopmentCode($class)) {
                    $violations[] = "{$file}: {$class}: a module may not use the tests, the tooling, the workbench or the examples.";

                    continue;
                }

                $package = self::owner($class);

                if ($package === null || $module === 'testkit') {
                    continue;
                }

                $violations[] = match (true) {
                    $module === 'contracts' => "{$file}: {$class} (package {$package}): contracts depends only on PHP.",
                    $production && array_key_exists($package, $manifest['suggest']) && ! array_key_exists($package, $manifest['require']) => "{$file}: {$class} (package {$package}): {$module} runs in production and may not use a package composer.json only suggests.",
                    ! array_key_exists($package, $manifest['require']) && ! array_key_exists($package, $manifest['suggest']) => "{$file}: {$class} (package {$package}): composer.json neither requires nor suggests {$package}.",
                    default => null,
                };
            }
        }

        return array_values(array_filter($violations, is_string(...)));
    }

    /**
     * The packages the module's src uses, sorted, without PHP's own classes and this package's.
     *
     * @return list<string>
     */
    public static function packagesUsedBy(string $module): array
    {
        $packages = [];

        foreach (self::classesUsedByFile(Codebase::root().'/packages/'.$module.'/src') as $classes) {
            foreach ($classes as $class) {
                $package = self::moduleOf($class) === null && ! self::isDevelopmentCode($class) ? self::owner($class) : null;

                if ($package !== null) {
                    $packages[$package] = $package;
                }
            }
        }

        sort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * The require and suggest sections of composer.json.
     *
     * @return array{require: array<string, string>, suggest: array<string, string>}
     *
     * @throws JsonException
     */
    public static function manifest(): array
    {
        $path = Codebase::root().'/composer.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        return [
            'require' => self::stringMap($data['require'] ?? []),
            'suggest' => self::stringMap($data['suggest'] ?? []),
        ];
    }

    /**
     * The module of a class of this package, or null.
     */
    public static function moduleOf(string $class): ?string
    {
        foreach (self::MODULES as $module => $namespace) {
            if (str_starts_with($class, $namespace.'\\')) {
                return $module;
            }
        }

        return null;
    }

    private static function isDevelopmentCode(string $class): bool
    {
        return array_any(self::DEVELOPMENT_CODE, fn (string $namespace): bool => str_starts_with($class, $namespace.'\\'));
    }

    /**
     * Every fully qualified class-like name used in code, per PHP file below the directory.
     *
     * @return array<string, list<string>>
     */
    private static function classesUsedByFile(string $directory): array
    {
        $parser = new ParserFactory()->createForHostVersion();
        $used = [];

        foreach (self::phpFiles($directory) as $file) {
            $statements = $parser->parse((string) file_get_contents($file)) ?? [];
            $statements = new NodeTraverser(new NameResolver)->traverse($statements);
            $names = [];

            foreach (new NodeFinder()->findInstanceOf($statements, Node\Name\FullyQualified::class) as $name) {
                $class = $name->toString();

                if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class) || (! function_exists($class) && ! defined($class))) {
                    $names[$class] = $class;
                }
            }

            sort($names, SORT_STRING);
            $used[$file] = $names;
        }

        return $used;
    }

    /**
     * The installed package that holds the class, null for a class built into PHP. A class the
     * autoloader cannot find is `unresolved:<class>`, which no manifest declares.
     */
    private static function owner(string $class): ?string
    {
        if (in_array($class, self::COMPOSER_RUNTIME, true)) {
            return 'composer-runtime-api';
        }

        if ((class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) && new ReflectionClass($class)->isInternal()) {
            return null;
        }

        $file = self::checkoutLoader()->findFile($class);
        $file = is_string($file) ? realpath($file) : false;

        if (! is_string($file)) {
            return 'unresolved:'.$class;
        }

        $owner = null;
        $longest = 0;

        foreach (self::checkoutInstallPaths() as $package => $path) {
            if (str_starts_with($file, $path.'/') && strlen($path) > $longest) {
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
     * The Composer autoloader of this checkout's vendor directory. Other autoloaders can be
     * registered in the same process, such as the one inside phpstan.phar, which PHPStan's
     * bootstrap registers first once a test loads a class of the phar; they are never asked.
     */
    private static function checkoutLoader(): ClassLoader
    {
        $vendor = realpath(Codebase::root().'/vendor');

        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if ($vendor !== false && realpath($vendorDir) === $vendor) {
                return $loader;
            }
        }

        throw new RuntimeException('The autoloader of '.Codebase::root().'/vendor is not registered.');
    }

    /**
     * The real install path of every package installed in this checkout's vendor directory, the
     * root package included, by name. InstalledVersions also reads the installed.php of every other
     * registered autoloader, such as phpstan.phar's, which lists psr/log and symfony/console with
     * paths inside the phar, and its getInstallPath() answers with the first it finds; only the
     * data set whose root is this checkout counts.
     *
     * @return array<string, string>
     */
    private static function checkoutInstallPaths(): array
    {
        $root = realpath(Codebase::root());

        foreach (InstalledVersions::getAllRawData() as $data) {
            $rootPath = realpath($data['root']['install_path']);

            if ($root === false || $rootPath !== $root) {
                continue;
            }

            $paths = [$data['root']['name'] => $rootPath];

            foreach ($data['versions'] as $package => $version) {
                $path = isset($version['install_path']) ? realpath($version['install_path']) : false;

                if (is_string($path)) {
                    $paths[$package] = $path;
                }
            }

            return $paths;
        }

        throw new RuntimeException('Composer has no installed data for '.Codebase::root().'.');
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
        $framework = self::checkoutInstallPaths()['laravel/framework'] ?? null;
        $manifest = $framework === null ? null : json_decode((string) file_get_contents($framework.'/composer.json'), true);
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

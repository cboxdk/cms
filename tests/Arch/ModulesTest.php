<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Cli\CliServiceProvider;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Cbox\Cms\Http\HttpServiceProvider;
use Cbox\Cms\Mcp\McpServiceProvider;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\ModuleDependencies;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Larastan\Larastan\ApplicationResolver;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
 * Cbox CMS is one Composer package, cboxdk/cms, like statamic/cms (GUARDRAILS 2.6, PRD 2.32). The
 * kernel's modules are namespaces with their code in packages/<module>/src, and no package
 * boundary keeps them apart, so these rules do: contracts depends only on PHP, core, http, cli and mcp
 * never use the testkit or the generators, and no production module uses a package that
 * composer.json only suggests (ModuleDependencies). The last tests plant each kind of violation in
 * a scratch directory and check that the rule reports it.
 */

/**
 * The root composer.json.
 *
 * @return array<array-key, mixed>
 */
function modulesComposer(): array
{
    $composer = json_decode((string) file_get_contents(Codebase::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    return is_array($composer) ? $composer : throw new RuntimeException('composer.json is not a JSON object.');
}

/**
 * A map of strings below a key path of the root composer.json.
 *
 * @return array<array-key, mixed>
 */
function modulesSection(string ...$keys): array
{
    $value = modulesComposer();

    foreach ($keys as $key) {
        $value = is_array($value) ? ($value[$key] ?? null) : null;
    }

    return is_array($value) ? $value : [];
}

/**
 * A scratch directory with one PHP file that holds the code, as a module's src would.
 */
function modulesPlant(string $code): string
{
    $directory = sys_get_temp_dir().'/cms-modules-'.bin2hex(random_bytes(6));

    if (! mkdir($directory) || file_put_contents($directory.'/Planted.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Planted;\n\n".$code."\n") === false) {
        throw new RuntimeException("Cannot write the planted file in {$directory}.");
    }

    return $directory;
}

function modulesUnplant(string $directory): void
{
    @unlink($directory.'/Planted.php');
    @rmdir($directory);
}

/**
 * The violations the rules of the module report for the planted code, with the planted file named
 * <plant>/Planted.php.
 *
 * @param  array{require: array<string, string>, suggest: array<string, string>}|null  $manifest
 * @return list<string>
 */
function modulesViolationsOf(string $module, string $code, ?array $manifest = null): array
{
    $directory = modulesPlant($code);

    try {
        return array_map(
            static fn (string $violation): string => str_replace($directory.'/', '<plant>/', $violation),
            ModuleDependencies::violations($module, $directory, $manifest),
        );
    } finally {
        modulesUnplant($directory);
    }
}

arch('modules: the repository is the one package cboxdk/cms, a library with no manifest per module', function (): void {
    $composer = modulesComposer();
    $pathRepositories = array_values(array_filter(
        modulesSection('repositories'),
        static fn (mixed $repository): bool => is_array($repository) && ($repository['type'] ?? null) === 'path',
    ));

    expect($composer['name'] ?? null)->toBe(ModuleDependencies::PACKAGE)
        ->and($composer['type'] ?? null)->toBe('library')
        ->and($composer['license'] ?? null)->toBe('MIT')
        ->and(modulesSection('require'))->toHaveKey('php', '^8.5')
        ->and(glob(Codebase::root().'/packages/*/composer.json') ?: [])->toBe([]);

    // A path repository may bring fixture packages from workbench/, never a module.
    foreach ($pathRepositories as $repository) {
        expect($repository['url'] ?? null)->toBeString()->toStartWith('workbench/');
    }
});

arch('modules: every directory below packages/ is a module, autoloaded from its src and its tests from its tests', function (): void {
    $directories = array_map(basename(...), glob(Codebase::root().'/packages/*', GLOB_ONLYDIR) ?: []);
    $autoload = [];
    $autoloadDev = [];

    foreach (ModuleDependencies::MODULES as $module => $namespace) {
        $autoload[$namespace.'\\'] = "packages/{$module}/src/";
        $autoloadDev[$namespace.'\\Tests\\'] = "packages/{$module}/tests/";
    }

    ksort($autoload);
    $psr4 = modulesSection('autoload', 'psr-4');
    ksort($psr4);

    expect($directories)->toEqualCanonicalizing(array_keys(ModuleDependencies::MODULES))
        ->and($psr4)->toBe($autoload)
        ->and(modulesSection('autoload-dev', 'psr-4'))->toMatchArray($autoloadDev);
});

arch('modules: the service providers of the modules are listed in extra.laravel.providers', function (): void {
    $providers = modulesSection('extra', 'laravel', 'providers');

    expect($providers)->toBe([
        CoreServiceProvider::class,
        HttpServiceProvider::class,
        McpServiceProvider::class,
        CliServiceProvider::class,
        GeneratorsServiceProvider::class,
    ]);

    foreach ($providers as $provider) {
        expect(is_string($provider) && class_exists($provider))->toBeTrue(var_export($provider, true).' does not exist.');
    }
});

arch('modules: the workbench registers the providers of extra.laravel.providers first, in the CLI and in every test case', function (): void {
    $testbench = Yaml::parseFile(Codebase::root().'/testbench.yaml');
    $providers = is_array($testbench) && is_array($testbench['providers'] ?? null) ? $testbench['providers'] : [];

    expect(array_slice($providers, 0, 5))->toBe(modulesSection('extra', 'laravel', 'providers'))
        ->and(array_slice($providers, 5))->toBe([WorkbenchServiceProvider::class]);
});

arch('modules: the heavy development tools are suggested and required for development, never in require', function (): void {
    $suggested = array_keys(modulesSection('suggest'));

    expect($suggested)->toContain('phpstan/phpstan', 'larastan/larastan', 'rector/rector', 'laravel/pint', 'orchestra/testbench', 'symfony/yaml', 'opis/json-schema')
        ->and(array_intersect($suggested, array_keys(modulesSection('require'))))->toBe([])
        ->and(array_diff($suggested, array_keys(modulesSection('require-dev'))))->toBe([]);
});

arch('modules: each module keeps to its boundaries', function (string $module): void {
    Rules::none(ModuleDependencies::violations($module), "The module {$module} breaks the module rules (GUARDRAILS 2.6):");
})->with(array_keys(ModuleDependencies::MODULES));

arch('modules: the generators use symfony/yaml, opis/json-schema and the Composer runtime, so none is dead weight', function (): void {
    expect(ModuleDependencies::packagesUsedBy('generators'))->toContain('symfony/yaml', 'opis/json-schema', 'composer-runtime-api')
        ->and(ModuleDependencies::packagesUsedBy('contracts'))->toBe([]);
});

arch('modules: the rules report contracts using another module or any package', function (): void {
    expect(modulesViolationsOf('contracts', 'final class Planted { public function of(\Cbox\Cms\Core\CoreServiceProvider $core): void {} }'))
        ->toBe(['<plant>/Planted.php: Cbox\Cms\Core\CoreServiceProvider (module core): contracts may not use cli, core, generators, http, mcp, testkit.'])
        ->and(modulesViolationsOf('contracts', 'final class Planted { public function of(\Illuminate\Support\Collection $items): void {} }'))
        ->toBe(['<plant>/Planted.php: Illuminate\Support\Collection (package illuminate/support): contracts depends only on PHP.']);
});

arch('modules: the rules report core, http, cli and mcp using the testkit or the generators', function (string $module, string $class, string $owner): void {
    expect(modulesViolationsOf($module, "final class Planted { public function of(\\{$class} \$used): void {} }"))
        ->toBe(["<plant>/Planted.php: {$class} (module {$owner}): {$module} may not use generators, testkit."]);
})->with([
    'core using the testkit' => ['core', FakeClock::class, 'testkit'],
    'core using the generators' => ['core', GeneratorsServiceProvider::class, 'generators'],
    'http using the testkit' => ['http', FakeClock::class, 'testkit'],
    'cli using the generators' => ['cli', GeneratorsServiceProvider::class, 'generators'],
    'mcp using the testkit' => ['mcp', FakeClock::class, 'testkit'],
    'mcp using the generators' => ['mcp', GeneratorsServiceProvider::class, 'generators'],
]);

arch('modules: the rules report a production module using a package composer.json only suggests', function (string $module, string $class, string $package): void {
    expect(modulesViolationsOf($module, "final class Planted { public function of(\\{$class} \$used): void {} }"))
        ->toBe(["<plant>/Planted.php: {$class} (package {$package}): {$module} runs in production and may not use a package composer.json only suggests."]);
})->with([
    'core using symfony/yaml' => ['core', Yaml::class, 'symfony/yaml'],
    'core using opis/json-schema' => ['core', CompliantValidator::class, 'opis/json-schema'],
    'cli using Larastan' => ['cli', ApplicationResolver::class, 'larastan/larastan'],
    'http using PHPUnit' => ['http', Assert::class, 'phpunit/phpunit'],
    'mcp using opis/json-schema' => ['mcp', CompliantValidator::class, 'opis/json-schema'],
]);

arch('modules: the rules report a module using the tests, the tooling, the workbench or the examples', function (): void {
    expect(modulesViolationsOf('generators', 'final class Planted { public function of(\Cbox\Cms\Tests\TestCase $test): void {} }'))
        ->toBe(['<plant>/Planted.php: Cbox\Cms\Tests\TestCase: a module may not use the tests, the tooling, the workbench or the examples.']);
});

arch('modules: the rules report a package the generators use that composer.json neither requires nor suggests', function (string $package, string $class): void {
    $manifest = ModuleDependencies::manifest();
    unset($manifest['require'][$package], $manifest['suggest'][$package]);

    expect(modulesViolationsOf('generators', "final class Planted { public function of(\\{$class} \$used): void {} }", $manifest))
        ->toBe(["<plant>/Planted.php: {$class} (package {$package}): composer.json neither requires nor suggests {$package}."]);
})->with([
    'symfony/yaml' => ['symfony/yaml', Yaml::class],
    'opis/json-schema' => ['opis/json-schema', CompliantValidator::class],
    'illuminate/console' => ['illuminate/console', Command::class],
]);

arch('modules: another registered autoloader that lists a package elsewhere does not change its owner', function (): void {
    // phpstan.phar registers its own Composer autoloader first once a test loads a class of the
    // phar, and its installed.php lists psr/log and symfony/console with paths inside the phar.
    // The rules resolve a class through this checkout's autoloader and installed data only, so
    // the classes still belong to psr/log and symfony/console, never to cboxdk/cms.
    $vendor = sys_get_temp_dir().'/cms-modules-vendor-'.bin2hex(random_bytes(6)).'/vendor';
    $installed = [
        'root' => ['name' => 'acme/other', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => 'phar:///nowhere/other.phar', 'aliases' => [], 'dev' => false],
        'versions' => [
            'psr/log' => ['pretty_version' => '3.0.0', 'version' => '3.0.0.0', 'reference' => null, 'type' => 'library', 'install_path' => 'phar:///nowhere/other.phar/vendor/psr/log', 'aliases' => [], 'dev_requirement' => false],
            'symfony/console' => ['pretty_version' => '7.0.0', 'version' => '7.0.0.0', 'reference' => null, 'type' => 'library', 'install_path' => 'phar:///nowhere/other.phar/vendor/symfony/console', 'aliases' => [], 'dev_requirement' => false],
        ],
    ];

    if (! mkdir($vendor.'/composer', recursive: true) || file_put_contents($vendor.'/composer/installed.php', '<?php return '.var_export($installed, true).';') === false) {
        throw new RuntimeException("Cannot write {$vendor}/composer/installed.php.");
    }

    $loader = new ClassLoader($vendor);
    $loader->register(true);

    try {
        expect(modulesViolationsOf('cli', 'final class Planted { public function of(\Psr\Log\LoggerInterface $log, \Symfony\Component\Console\Output\OutputInterface $output): void {} }'))->toBe([])
            ->and(ModuleDependencies::packagesUsedBy('cli'))->toContain('psr/log', 'symfony/console')
            ->and(in_array(ModuleDependencies::PACKAGE, ModuleDependencies::packagesUsedBy('cli'), true))->toBeFalse();
    } finally {
        $loader->unregister();
        @unlink($vendor.'/composer/installed.php');
        @rmdir($vendor.'/composer');
        @rmdir($vendor);
        @rmdir(dirname($vendor));
    }
});

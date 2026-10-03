<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;
use SimpleXMLElement;
use SplFileInfo;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * Every gate tool that keeps a cache or other state between runs keeps it in this checkout, in
 * the git-ignored .cache/ with a directory per tool, so parallel worktrees never share it. PHPStan,
 * Rector and Pint default to the shared system temp directory; the root configuration moves them,
 * and Pint, whose compiled views pint.json cannot move, runs through tools/bin/pint.php with the
 * temp directory in .cache/pint/tmp. PHPUnit's cache directory was in the checkout already. tsc,
 * ESLint and Prettier keep no cache with the flags the gates run them with.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * The directory that holds the tool caches, relative to the repository root.
 */
const TOOL_CACHE = '.cache';

/**
 * The boot lock of the testkit's PHPStan configuration is the one file a gate tool may leave in
 * the system temp directory: its name carries a hash of the checkout's path (LaravelBootLock).
 */
const BOOT_LOCK = '/^cbox-cms-laravel-boot-[0-9a-f]{32}\.lock$/';

function toolCacheRoot(): string
{
    return Phpstan::root();
}

/**
 * Rector's cache directories as rector.php configures them.
 *
 * @return array{files: string, container: string}
 */
function rectorCacheDirectories(): array
{
    $builder = require toolCacheRoot().'/rector.php';

    if (! is_object($builder)) {
        throw new UnexpectedValueException('rector.php must return the Rector config builder.');
    }

    // Rector keeps the configured directories in private state; reading it checks the effective
    // configuration and not the text of the file.
    $files = new ReflectionProperty($builder, 'cacheDirectory')->getValue($builder);
    $container = new ReflectionProperty($builder, 'containerCacheDirectory')->getValue($builder);

    if (! is_string($files) || ! is_string($container)) {
        throw new UnexpectedValueException('rector.php sets no cache directory or no container cache directory.');
    }

    return ['files' => $files, 'container' => $container];
}

/**
 * Pint's cache file from pint.json. Pint resolves it against the directory it runs in, and the
 * Composer scripts run it from the repository root.
 */
function pintCacheFile(): string
{
    $pint = json_decode((string) file_get_contents(toolCacheRoot().'/pint.json'), true, 512, JSON_THROW_ON_ERROR);
    $file = is_array($pint) ? ($pint['cache-file'] ?? null) : null;

    if (! is_string($file)) {
        throw new UnexpectedValueException('pint.json sets no cache-file.');
    }

    return toolCacheRoot().'/'.$file;
}

function phpunitCacheDirectory(): string
{
    $phpunit = new SimpleXMLElement((string) file_get_contents(toolCacheRoot().'/phpunit.xml'));

    return toolCacheRoot().'/'.$phpunit['cacheDirectory'];
}

/**
 * Where each tool keeps its state, as the configuration resolves it.
 *
 * @return array<string, string>
 */
function toolCacheLocations(): array
{
    $rector = rectorCacheDirectories();
    $tmpDir = Phpstan::parameters('phpstan.neon')->value('tmpDir');

    if (! is_string($tmpDir)) {
        throw new UnexpectedValueException('PHPStan reports no tmpDir.');
    }

    return [
        'PHPStan' => $tmpDir,
        'Rector files' => $rector['files'],
        'Rector container' => $rector['container'],
        'Pint' => dirname(pintCacheFile()),
        'PHPUnit' => phpunitCacheDirectory(),
        'Vitest' => vitestCacheDirectory(),
    ];
}

/**
 * The cacheDir vitest.config.ts sets, where Vitest keeps the results of the JS suites.
 */
function vitestCacheDirectory(): string
{
    $config = (string) file_get_contents(toolCacheRoot().'/vitest.config.ts');

    if (preg_match_all("/cacheDir: join\\(ROOT, '([^']+)'\\)/", $config, $matches) !== 1) {
        throw new UnexpectedValueException('vitest.config.ts does not set one cacheDir below the root.');
    }

    return toolCacheRoot().'/'.$matches[1][0];
}

/**
 * Where tools/bin/pint.php points Pint's temp directory, relative to the repository root.
 */
const PINT_TEMP = TOOL_CACHE.'/pint/tmp';

/**
 * The environment of a terminal or a CI job, with the system temp directory at $temporary: every
 * inherited variable removed but those PHP needs to start as it does here. Tools write differently
 * under a coding agent: Pint finds one by variables such as CLAUDECODE and AI_AGENT
 * (laravel/agent-detector) and then prints JSON instead of rendering its summary views, so under an
 * agent it left nothing in the temp directory while in CI it did.
 *
 * @return array<string, string|false>
 */
function terminalEnvironment(string $temporary): array
{
    $environment = [];

    foreach ([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)] as $name) {
        if (is_string($name)) {
            $environment[$name] = false;
        }
    }

    foreach (['PATH', 'HOME', 'PHP_INI_SCAN_DIR', 'XDEBUG_MODE'] as $name) {
        $value = getenv($name);

        if (is_string($value)) {
            $environment[$name] = $value;
        }
    }

    return ['TMPDIR' => $temporary] + $environment;
}

/**
 * Runs a command from the repository root in a terminal's environment with the system temp
 * directory pointed at an empty scratch directory, and returns what the command left there,
 * relative to it. $output receives what the command printed.
 *
 * @param  list<string>  $command
 *
 * @param-out  string  $output
 *
 * @return list<string>
 */
function leftInSystemTemp(array $command, ?string &$output = null): array
{
    $temporary = ScratchDirectory::make('cbox-cms-tool-temp-');
    $environment = terminalEnvironment($temporary);

    // PHP's sys_temp_dir setting would win over TMPDIR and make this test pass without looking.
    $probe = new Process([PHP_BINARY, '-r', 'echo realpath(sys_get_temp_dir());'], toolCacheRoot(), $environment);
    $probe->mustRun();

    if ($probe->getOutput() !== $temporary) {
        throw new RuntimeException("A PHP process with TMPDIR={$temporary} uses {$probe->getOutput()} as its temp directory.");
    }

    $process = new Process($command, toolCacheRoot(), $environment, null, 300);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(implode(' ', $command)." failed:\n".$process->getOutput().$process->getErrorOutput());
    }

    $output = $process->getOutput();

    $left = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        if ($entry instanceof SplFileInfo) {
            $left[] = substr($entry->getPathname(), strlen($temporary) + 1);
        }
    }

    sort($left);

    return $left;
}

function toolCacheProbe(): string
{
    return ScratchDirectory::write(ScratchDirectory::make('cbox-cms-tool-probe-').'/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        function cmsToolCacheProbe(int $value): int
        {
            return $value + 1;
        }

        PHP);
}

it('keeps PHPStan, Rector, Pint and Vitest state in a directory of its own below .cache/ in the repository root', function (): void {
    $root = toolCacheRoot();
    $rector = rectorCacheDirectories();
    $phpstan = Phpstan::parameters('phpstan.neon');

    expect($phpstan->value('tmpDir'))->toBe($root.'/'.TOOL_CACHE.'/phpstan')
        ->and($phpstan->value('resultCachePath'))->toBe($root.'/'.TOOL_CACHE.'/phpstan/resultCache.php')
        ->and($rector['files'])->toBe($root.'/'.TOOL_CACHE.'/rector/files')
        ->and($rector['container'])->toBe($root.'/'.TOOL_CACHE.'/rector/container')
        ->and(pintCacheFile())->toBe($root.'/'.TOOL_CACHE.'/pint/pint.cache')
        ->and(vitestCacheDirectory())->toBe($root.'/'.TOOL_CACHE.'/vitest');
});

it('keeps PHPUnit\'s cache directory in the repository root', function (): void {
    expect(phpunitCacheDirectory())->toBe(toolCacheRoot().'/.phpunit.cache');
});

it('gives every tool its own directory, none inside another', function (): void {
    $locations = toolCacheLocations();

    foreach ($locations as $tool => $location) {
        expect($location)->toStartWith(toolCacheRoot().'/');

        foreach ($locations as $other => $otherLocation) {
            if ($other !== $tool) {
                expect($location)->not->toStartWith($otherLocation.'/', "{$tool} lies inside the directory of {$other}.")
                    ->and($location)->not->toBe($otherLocation, "{$tool} shares its directory with {$other}.");
            }
        }
    }
});

it('keeps every cache directory out of git', function (string $tool): void {
    $location = toolCacheLocations()[$tool];
    $relative = substr($location, strlen(toolCacheRoot()) + 1).'/any-file';

    $ignored = new Process(['git', 'check-ignore', '--quiet', '--no-index', $relative], toolCacheRoot());
    $ignored->run();

    expect($ignored->getExitCode())->toBe(0, "git does not ignore {$relative}.");
})->with(['PHPStan', 'Rector files', 'Rector container', 'Pint', 'PHPUnit', 'Vitest']);

it('leaves nothing but the checkout\'s boot lock in the system temp directory when PHPStan runs', function (): void {
    $left = leftInSystemTemp([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress', toolCacheProbe()]);

    expect($left)->toHaveCount(1)
        ->and($left[0] ?? '')->toMatch(BOOT_LOCK)
        ->and(glob(toolCacheRoot().'/'.TOOL_CACHE.'/phpstan/cache/*') ?: [])->not->toBeEmpty();
});

it('leaves nothing in the system temp directory when Rector runs', function (): void {
    $left = leftInSystemTemp([PHP_BINARY, 'vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', toolCacheProbe()]);

    expect($left)->toBe([])
        ->and(glob(toolCacheRoot().'/'.TOOL_CACHE.'/rector/container/*') ?: [])->not->toBeEmpty();
});

it('leaves nothing in the system temp directory when Pint runs as the lint scripts run it', function (): void {
    $left = leftInSystemTemp([PHP_BINARY, 'tools/bin/pint.php', '--test', toolCacheProbe()], $output);

    // Pint rendered its summary views, as in a terminal and not as under an agent, and compiled
    // them below .cache/pint. Laravel rewrites a compiled view only when it changes, so its time
    // says nothing about this run.
    expect($output)->toContain('PASS')
        ->and($output)->not->toContain('"tool":"pint"')
        ->and($left)->toBe([])
        ->and(pintCacheFile())->toBeFile()
        ->and(glob(toolCacheRoot().'/'.PINT_TEMP.'/*.php') ?: [])->not->toBeEmpty();
});

it('runs Pint through tools/bin/pint.php in the lint scripts', function (): void {
    $composer = json_decode((string) file_get_contents(toolCacheRoot().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

    expect($scripts['lint'] ?? null)->toBe('@php tools/bin/pint.php')
        ->and($scripts['lint:check'] ?? null)->toBe('@php tools/bin/pint.php --test --parallel')
        ->and(dirname(toolCacheRoot().'/'.PINT_TEMP))->toBe(dirname(pintCacheFile()));
});

it('runs tsc, ESLint and Prettier without a cache, so they keep no state to share', function (): void {
    $package = json_decode((string) file_get_contents(toolCacheRoot().'/package.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($package) && is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
    $tsconfig = file_get_contents(toolCacheRoot().'/tsconfig.json').file_get_contents(toolCacheRoot().'/js/tooling/tsconfig.base.json');

    // A cache for one of them needs a location in .cache/ first, like the PHP tools.
    foreach (['typecheck', 'lint', 'format:check'] as $script) {
        $command = $scripts[$script] ?? null;

        if (! is_string($command)) {
            throw new UnexpectedValueException("package.json has no {$script} script.");
        }

        foreach (['--cache', '--incremental', '--build'] as $flag) {
            expect(str_contains($command, $flag))->toBeFalse("The {$script} script uses {$flag}.");
        }
    }

    foreach (['"incremental"', '"composite"', '"tsBuildInfoFile"'] as $option) {
        expect(str_contains($tsconfig, $option))->toBeFalse("The tsconfig sets {$option}.");
    }
});

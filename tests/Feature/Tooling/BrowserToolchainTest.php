<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ParallelWorker;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Pest\Browser\Playwright\Servers\PlaywrightNpmServer;
use ReflectionClassConstant;
use RuntimeException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

use function Orchestra\Testbench\workbench_path;

/*
 * The toolchain of gate 8 in GUARDRAILS 10: the Pest browser plugin from Composer and Playwright
 * from npm, pinned in the committed lock file. The plugin starts `node_modules/.bin/playwright`
 * itself and refuses a version below the one it was built against, so these tests check that the
 * pinned version is the installed one and is new enough. The browser binary comes from
 * `npx playwright install chromium`; the Browser suite fails without it.
 */

it('requires the Pest browser plugin as a dev dependency', function (): void {
    $composer = Node::jsonFile('composer.json');

    $require = $composer['require'] ?? null;

    expect($composer['require-dev'] ?? null)->toBeArray()->toHaveKey('pestphp/pest-plugin-browser', '^5.0')
        ->and(is_array($require) && array_key_exists('pestphp/pest-plugin-browser', $require))->toBeFalse()
        ->and(InstalledVersions::getVersion('pestphp/pest-plugin-browser'))->toStartWith('5.');
});

it('pins Playwright in package.json and the lock file, and installs that version', function (): void {
    $package = Node::jsonFile('package.json');
    $lock = Node::jsonFile('package-lock.json');
    $devDependencies = $package['devDependencies'] ?? null;
    $pinned = is_array($devDependencies) ? ($devDependencies['playwright'] ?? null) : null;
    $packages = $lock['packages'] ?? null;
    $locked = is_array($packages) ? ($packages['node_modules/playwright'] ?? null) : null;

    expect($pinned)->toBeString()->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(is_array($locked) ? ($locked['version'] ?? null) : null)->toBe($pinned)
        ->and(is_array($locked) ? ($locked['dev'] ?? null) : null)->toBeTrue();

    $process = Node::tool('playwright', ['--version']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe('Version '.(is_string($pinned) ? $pinned : ''));
});

it('pins a Playwright that the browser plugin accepts', function (): void {
    $package = Node::jsonFile('package.json');
    $devDependencies = $package['devDependencies'] ?? null;
    $pinned = is_array($devDependencies) ? ($devDependencies['playwright'] ?? null) : null;
    $minimum = new ReflectionClassConstant(PlaywrightNpmServer::class, 'PLAYWRIGHT_VERSION')->getValue();

    if (! is_string($pinned) || ! is_string($minimum)) {
        throw new UnexpectedValueException('package.json pins no Playwright, or the plugin names no minimum version.');
    }

    expect(version_compare($pinned, $minimum, '>='))
        ->toBeTrue("Playwright {$pinned} is older than {$minimum}, the minimum of the browser plugin.");
});

it('exits 0 from a browser run that finds the screenshots of an earlier run', function (): void {
    // Before the first browser test the plugin empties tests/Browser/Screenshots with @rmdir() on
    // subdirectories that need not exist. PHPUnit 13 counts such a warning outside a test, even
    // suppressed, unless the file is on its exclude list, so without tests/Pest.php adding the
    // plugin there every browser run after one that left a screenshot exited 1 and printed no
    // failure. The screenshots already there are kept aside and put back afterwards.
    $root = Phpstan::root();
    $screenshots = $root.'/tests/Browser/Screenshots';
    $files = new Filesystem;
    $kept = sys_get_temp_dir().'/cms-browser-screenshots-'.bin2hex(random_bytes(8));
    $hadScreenshots = $files->isDirectory($screenshots);

    // A copy, not a rename: the system temp directory can be on another file system.
    if ($hadScreenshots && (! $files->copyDirectory($screenshots, $kept) || ! $files->deleteDirectory($screenshots))) {
        throw new RuntimeException("Could not keep {$screenshots} aside in {$kept}.");
    }

    try {
        $files->ensureDirectoryExists($screenshots);
        $files->put($screenshots.'/from-an-earlier-run.png', 'png');

        // Its own Pest run, also when this test runs in a parallel worker.
        $run = new Process(
            [PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/Tooling/fixtures/browser-plugin-boot.php', '--colors=never'],
            $root,
            ParallelWorker::cleared(),
            timeout: 120,
        );
        $run->run();
        $output = $run->getOutput().$run->getErrorOutput();

        expect($run->getExitCode())->toBe(0, $output)
            ->and($output)->toContain('1 passed')
            // The plugin's clean-up ran, so the run met the directory it failed on.
            ->and($screenshots)->not->toBeDirectory();
    } finally {
        $files->deleteDirectory($screenshots);

        if ($hadScreenshots && (! $files->copyDirectory($kept, $screenshots) || ! $files->deleteDirectory($kept))) {
            throw new RuntimeException("Could not put the screenshots back from {$kept}.");
        }
    }
});

it('serves the workbench web routes, which the browser tests visit', function (): void {
    $testbench = (string) file_get_contents(Phpstan::root().'/testbench.yaml');

    expect($testbench)->toMatch('/^    web: true$/m')
        ->and(Phpstan::root().'/workbench/routes/web.php')->toBeFile();
});

it('finds the workbench routes in the monorepo after a test loads Rector, which registers another Composer root', function (): void {
    // Rector's bundled autoloader adds rector/rector-src as a Composer root package. Testbench
    // resolves the package root from Composer's root package unless TESTBENCH_WORKING_PATH is
    // defined, so without tests/Pest.php defining it every later test served no workbench routes.
    require_once Phpstan::root().'/vendor/rector/rector/vendor/autoload.php';

    expect(workbench_path('routes', 'web.php'))->toBe(Phpstan::root().'/workbench/routes/web.php');
});

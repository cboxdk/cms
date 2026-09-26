<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Pest\Browser\Playwright\Servers\PlaywrightNpmServer;
use ReflectionClassConstant;
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

    expect($composer['require-dev'] ?? null)->toBeArray()->toHaveKey('pestphp/pest-plugin-browser', '^4.3')
        ->and(is_array($require) && array_key_exists('pestphp/pest-plugin-browser', $require))->toBeFalse();
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

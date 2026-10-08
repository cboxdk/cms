<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Workbench;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\DevImage\Domain\PublishedPort;
use Cbox\Cms\Tooling\Workbench\Boundary\WorkbenchServeOptions;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchEnvironment;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchServe;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/*
 * The parts of composer dev:prepare's first step and composer workbench:serve that decide without
 * Docker (tools/src/Workbench): the workbench's application key in workbench/.env, which
 * tools/bin/workbench-env.php writes once and copies to Testbench's application, the port the
 * served workbench is published on, and what the panel needs before it is served. The script runs
 * here on a scratch checkout; tests/Feature/Tooling/DevImage/DevImageScriptTest.php runs
 * tools/bin/workbench-serve.php with a fake docker.
 */

const WORKBENCH_EXAMPLE = "APP_NAME=\"Cbox CMS\"\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\n\nDB_DATABASE=cms\n";

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Runs tools/bin/workbench-env.php in the directory.
 *
 * @return array{exitCode: int|null, output: string, errors: string}
 */
function runWorkbenchEnv(string $directory): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/workbench-env.php'], $directory);
    $process->setTimeout(60);
    $process->run();

    return ['exitCode' => $process->getExitCode(), 'output' => $process->getOutput(), 'errors' => $process->getErrorOutput()];
}

/**
 * A scratch checkout with workbench/.env.example and, unless told not to, Testbench's application.
 */
function workbenchCheckout(bool $application = true): string
{
    $root = ScratchDirectory::make('cbox-cms-workbench-env-');
    ScratchDirectory::write($root.'/'.WorkbenchEnvironment::EXAMPLE, WORKBENCH_EXAMPLE);

    if ($application) {
        mkdir(dirname($root.'/'.WorkbenchEnvironment::APPLICATION_FILE), 0o755, true);
    }

    return $root;
}

it('makes an application key as key:generate does, 32 random bytes in base64', function (): void {
    $key = WorkbenchEnvironment::newKey(str_repeat("\x01", 32));

    expect($key)->toBe('base64:'.base64_encode(str_repeat("\x01", 32)))
        ->and(strlen((string) base64_decode(substr($key, 7), true)))->toBe(32)
        ->and(static fn (): string => WorkbenchEnvironment::newKey('short'))->toThrow(InvalidArgumentException::class);
});

it('knows a key from a missing, empty or quoted empty one', function (string $settings, bool $has): void {
    expect(WorkbenchEnvironment::hasKey($settings))->toBe($has);
})->with([
    'a key' => ["APP_NAME=x\nAPP_KEY=base64:abc\n", true],
    'a quoted key' => ["APP_KEY=\"base64:abc\"\n", true],
    'empty' => ["APP_KEY=\nAPP_DEBUG=true\n", false],
    'empty at the end' => ['APP_KEY=', false],
    'quoted empty' => ["APP_KEY=\"\"\n", false],
    'single-quoted empty' => ["APP_KEY=''\n", false],
    'missing' => ["APP_NAME=x\n", false],
    'commented out' => ["# APP_KEY=base64:abc\n", false],
]);

it('writes the key into the example without a file, into the file\'s empty line or at its end, and leaves a file with a key alone', function (): void {
    expect(WorkbenchEnvironment::withKey(WORKBENCH_EXAMPLE, null, 'base64:new'))
        ->toBe(str_replace("APP_KEY=\n", "APP_KEY=base64:new\n", WORKBENCH_EXAMPLE))
        ->and(WorkbenchEnvironment::withKey(WORKBENCH_EXAMPLE, "DB_DATABASE=mine\nAPP_KEY=\n", 'base64:new'))->toBe("DB_DATABASE=mine\nAPP_KEY=base64:new\n")
        ->and(WorkbenchEnvironment::withKey(WORKBENCH_EXAMPLE, 'DB_DATABASE=mine', 'base64:new'))->toBe("DB_DATABASE=mine\nAPP_KEY=base64:new\n")
        ->and(WorkbenchEnvironment::withKey(WORKBENCH_EXAMPLE, "APP_KEY=base64:old\n", 'base64:new'))->toBeNull();
});

it('writes workbench/.env with a key once, copies it to Testbench\'s application, and changes nothing on a second run', function (): void {
    $root = workbenchCheckout();

    $first = runWorkbenchEnv($root);
    $settings = (string) file_get_contents($root.'/'.WorkbenchEnvironment::FILE);

    expect($first['exitCode'])->toBe(0, $first['errors'])
        ->and($first['output'])->toContain('wrote a new APP_KEY into workbench/.env, made from workbench/.env.example')
        ->and($first['output'])->toContain('copied workbench/.env to '.WorkbenchEnvironment::APPLICATION_FILE)
        ->and($settings)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')
        ->and(str_replace((string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=', $settings), '', WORKBENCH_EXAMPLE))->toBe('')
        ->and(file_get_contents($root.'/'.WorkbenchEnvironment::APPLICATION_FILE))->toBe($settings);

    $second = runWorkbenchEnv($root);

    expect($second['exitCode'])->toBe(0)
        ->and($second['output'])->toBe("workbench env: workbench/.env has an APP_KEY; unchanged.\n")
        ->and(file_get_contents($root.'/'.WorkbenchEnvironment::FILE))->toBe($settings);
});

it('keeps a developer\'s own settings when it gives them a key, and brings Testbench\'s stale copy up to date', function (): void {
    $root = workbenchCheckout();
    ScratchDirectory::write($root.'/'.WorkbenchEnvironment::FILE, "DB_DATABASE=mine\nAPP_KEY=\n");
    ScratchDirectory::write($root.'/'.WorkbenchEnvironment::APPLICATION_FILE, WORKBENCH_EXAMPLE);

    $run = runWorkbenchEnv($root);
    $settings = (string) file_get_contents($root.'/'.WorkbenchEnvironment::FILE);

    expect($run['exitCode'])->toBe(0)
        ->and($run['output'])->toContain("wrote a new APP_KEY into workbench/.env.\n")
        ->and($settings)->toStartWith("DB_DATABASE=mine\nAPP_KEY=base64:")
        ->and(file_get_contents($root.'/'.WorkbenchEnvironment::APPLICATION_FILE))->toBe($settings);
});

it('writes no application settings where there is no Testbench application, and fails without the example', function (): void {
    $root = workbenchCheckout(application: false);

    expect(runWorkbenchEnv($root)['exitCode'])->toBe(0)
        ->and(file_exists($root.'/'.WorkbenchEnvironment::APPLICATION_FILE))->toBeFalse();

    $empty = ScratchDirectory::make('cbox-cms-workbench-env-empty-');
    $run = runWorkbenchEnv($empty);

    expect($run['exitCode'])->toBe(1)
        ->and($run['errors'])->toContain('workbench/.env.example is missing')
        ->and(file_exists($empty.'/'.WorkbenchEnvironment::FILE))->toBeFalse();
});

it('publishes a port on 127.0.0.1 only, and refuses one that is no TCP port', function (): void {
    $port = new PublishedPort(8080, 8080);

    expect($port->option())->toBe('127.0.0.1:8080:8080')
        ->and($port->url())->toBe('http://127.0.0.1:8080')
        ->and(static fn (): PublishedPort => new PublishedPort(0, 8080))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): PublishedPort => new PublishedPort(8080, 65536))->toThrow(InvalidArgumentException::class);
});

it('serves on 127.0.0.1:8080 by default and on the port --port names, with the server on the container\'s port 8080', function (): void {
    $default = WorkbenchServeOptions::parse([]);
    $other = WorkbenchServeOptions::parse(['--port=9000']);

    expect($default->port->option())->toBe('127.0.0.1:8080:8080')
        ->and($default->panelUrl())->toBe('http://127.0.0.1:8080/cms')
        ->and($other->port->option())->toBe('127.0.0.1:9000:8080')
        ->and($other->command())->toBe(['php', 'vendor/bin/testbench', 'serve', '--host=0.0.0.0', '--port=8080', '--no-interaction'])
        ->and(static fn (): WorkbenchServe => WorkbenchServeOptions::parse(['--port=08080']))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): WorkbenchServe => WorkbenchServeOptions::parse(['8080']))->toThrow(InvalidArgumentException::class);
});

it('names what the panel misses before it is served, each with its fix', function (): void {
    $settings = "APP_KEY=base64:abc\n";

    expect(WorkbenchServe::problems($settings, null, true))->toBe([])
        ->and(WorkbenchServe::problems($settings, $settings, true))->toBe([])
        ->and(WorkbenchServe::problems(null, null, true))->toBe(['workbench/.env has no APP_KEY, without which the panel refuses every request. Run composer dev:prepare, which writes one.'])
        ->and(WorkbenchServe::problems($settings, "APP_KEY=\n", true))->toBe(['vendor/orchestra/testbench-core/laravel/.env is not what workbench/.env holds, and Testbench\'s application reads only its own copy. Run composer dev:prepare, which copies it.'])
        ->and(WorkbenchServe::problems("APP_KEY=\n", null, false))->toHaveCount(2)
        ->and(WorkbenchServe::problems($settings, null, false)[0] ?? '')->toContain(WorkbenchServe::PANEL_MANIFEST, 'composer panel:build');
});

it('serves the workbench whose site and password reset links are at the address it publishes', function (): void {
    expect(config('cbox-cms.sites.workbench.origin'))->toBe(WorkbenchServeOptions::parse([])->port->url())
        ->and(config('cbox-cms.identity.password_reset.url'))->toBe(WorkbenchServeOptions::parse([])->panelUrl().'/reset-password');
});

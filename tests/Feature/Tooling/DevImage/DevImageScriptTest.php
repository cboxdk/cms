<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\DevImage;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tooling\DevImage\Domain\CheckoutVolume;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;
use Cbox\Cms\Tooling\DevImage\Domain\VolumeKind;
use Cbox\Cms\Tooling\Workbench\Boundary\WorkbenchServeOptions;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchEnvironment;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchServe;
use Symfony\Component\Process\Process;

/*
 * composer check, composer test:affected and composer image:run run in the php-baseimages dev
 * image, locally as in CI (PROGRESS.md, "Beslutninger fra Sylvester", 30 September). From the host,
 * tools/bin/dev-image.php starts a container of the image for the checkout: mounted at the same
 * path, as the host user, on the network of the shared services, which it never starts. The script
 * runs here on a scratch repository with a linked worktree and a fake docker on the PATH that
 * records how it was called and answers docker compose ps with the containers a test gives it.
 * composer workbench:serve, tools/bin/workbench-serve.php, starts the same container for the
 * development server and publishes its port on the host's 127.0.0.1 only.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A scratch repository and a linked worktree of it.
 *
 * @return array{main: string, worktree: string}
 */
function devImageCheckouts(): array
{
    $repository = ScratchRepository::make('cbox-cms-dev-image-main-');
    $repository->write('compose.yaml', "name: scratch\n")->commit('compose');
    $worktree = ScratchDirectory::make('cbox-cms-dev-image-worktree-').'/probe';
    $repository->git('worktree', 'add', '--quiet', '--detach', $worktree);

    return ['main' => $repository->root, 'worktree' => (string) realpath($worktree)];
}

/**
 * One line of `docker compose ps --all --format json`.
 */
function composeContainer(string $service, string $state = 'running', string $health = 'healthy', string $networks = 'scratch_default'): string
{
    return json_encode(['Service' => $service, 'State' => $state, 'Health' => $health, 'Networks' => $networks, 'Name' => "scratch-{$service}-1"], JSON_THROW_ON_ERROR);
}

/**
 * Runs a tool script of this checkout in a directory, outside the dev image, with a fake docker
 * first on the PATH that appends its arguments to a log, one per line, prints $ps for docker
 * compose ps, and exits with $psExit there, with 0 for docker volume inspect when $volumeExists,
 * and with $runExit for docker run.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{exitCode: int|null, output: string, errors: string, calls: list<list<string>>, home: string}
 */
function runDevImageScript(string $directory, string $script, array $arguments, string $ps, int $psExit = 0, bool $volumeExists = true, int $runExit = 0, array $environment = []): array
{
    $bin = ScratchDirectory::make('cbox-cms-fake-docker-');
    $home = ScratchDirectory::make('cbox-cms-dev-image-home-');
    $log = $bin.'/calls.log';
    ScratchDirectory::write($bin.'/ps.json', $ps);
    ScratchDirectory::write($bin.'/docker', <<<'SH'
        #!/bin/sh
        {
            for argument in "$@"; do printf '%s\n' "$argument"; done
            printf '%s\n' '--end-of-call--'
        } >> "$FAKE_DOCKER_LOG"
        case "$1" in
            compose)
                if [ "$FAKE_DOCKER_PS_EXIT" != 0 ]; then
                    echo 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.' >&2
                    exit "$FAKE_DOCKER_PS_EXIT"
                fi
                cat "$FAKE_DOCKER_PS"
                exit 0
                ;;
            volume)
                if [ "$2" = inspect ]; then exit "$FAKE_DOCKER_VOLUME_INSPECT"; fi
                exit 0
                ;;
            run)
                exit "$FAKE_DOCKER_RUN_EXIT"
                ;;
        esac
        exit 0
        SH);
    chmod($bin.'/docker', 0o755);

    $process = new Process(
        [PHP_BINARY, Phpstan::root().'/tools/bin/'.$script, ...$arguments],
        $directory,
        [
            // Outside the dev image, as on a developer's host.
            DevImage::TIER_VARIABLE => false,
            'HOME' => $home,
            'PATH' => $bin.':'.getenv('PATH'),
            'FAKE_DOCKER_LOG' => $log,
            'FAKE_DOCKER_PS' => $bin.'/ps.json',
            'FAKE_DOCKER_PS_EXIT' => (string) $psExit,
            'FAKE_DOCKER_VOLUME_INSPECT' => $volumeExists ? '0' : '1',
            'FAKE_DOCKER_RUN_EXIT' => (string) $runExit,
            'TERM' => false,
            'CMS_CI_BASE_REF' => false,
            ...$environment,
        ],
    );
    $process->setTimeout(120);
    $process->run();

    $calls = [];
    $call = [];

    foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) ?: [] : [] as $line) {
        if ($line === '--end-of-call--') {
            $calls[] = $call;
            $call = [];

            continue;
        }

        $call[] = $line;
    }

    return ['exitCode' => $process->getExitCode(), 'output' => $process->getOutput(), 'errors' => $process->getErrorOutput(), 'calls' => $calls, 'home' => (string) realpath($home)];
}

function devImageHostId(string $option): string
{
    $process = new Process(['id', $option]);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * The values that follow each occurrence of an option in a call.
 *
 * @param  list<string>  $call
 * @return list<string>
 */
function optionValues(array $call, string $option): array
{
    $values = [];

    foreach ($call as $index => $argument) {
        if ($argument === $option && isset($call[$index + 1])) {
            $values[] = $call[$index + 1];
        }
    }

    return $values;
}

/**
 * The docker run call that runs the command, the last call of a run.
 *
 * @param  list<list<string>>  $calls
 * @return list<string>
 */
function dockerRun(array $calls): array
{
    $runs = array_values(array_filter($calls, static fn (array $call): bool => ($call[0] ?? null) === 'run' && ! in_array('0:0', $call, true)));

    expect($runs)->toHaveCount(1);

    return $runs[0];
}

function healthyServices(): string
{
    return composeContainer('postgres')."\n".composeContainer('valkey')."\n";
}

it('runs the command from a linked worktree in the dev image: the worktree at its own path as the working directory, the main .git, its node_modules volume and ~/.pest, as the host user on the services\' network', function (): void {
    ['main' => $main, 'worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'vendor/bin/pest', '--testsuite=Browser'], healthyServices(), runExit: 3);
    $docker = dockerRun($run['calls']);
    $hash = substr(hash('sha256', $worktree), 0, 12);

    expect($run['exitCode'])->toBe(3)
        ->and($run['calls'][0])->toBe(['compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'ps', '--all', '--format', 'json', 'postgres', 'valkey'])
        ->and(array_slice($docker, 0, 4))->toBe(['run', '--rm', '--init', '--entrypoint'])
        ->and(optionValues($docker, '--entrypoint'))->toBe([''])
        ->and(optionValues($docker, '--user'))->toBe([devImageHostId('-u').':'.devImageHostId('-g')])
        ->and(optionValues($docker, '--user'))->not->toBe(['0:0'])
        ->and(optionValues($docker, '--hostname'))->toBe([(string) gethostname()])
        ->and(optionValues($docker, '--network'))->toBe(['scratch_default'])
        ->and(optionValues($docker, '--workdir'))->toBe([$worktree])
        ->and(optionValues($docker, '--volume'))->toBe([
            $worktree.':'.$worktree,
            $main.'/.git:'.$main.'/.git',
            'laravel-cms-node-modules-'.$hash.':'.$worktree.'/node_modules',
            'laravel-cms-cache-'.$hash.':'.$worktree.'/.cache',
            'laravel-cms-bootstrap-cache-'.$hash.':'.$worktree.'/vendor/orchestra/testbench-core/laravel/bootstrap/cache',
            $worktree.'/vendor/orchestra/testbench-core/laravel/bootstrap/cache:/cms-host/bootstrap-cache:ro',
            $run['home'].'/.pest:/tmp/.pest',
        ])
        ->and(optionValues($docker, '--env'))->toBe([
            'HOME=/tmp',
            'DB_HOST=postgres',
            'DB_PORT=5432',
            'REDIS_HOST=valkey',
            'REDIS_PORT=6379',
            'XDEBUG_MODE=off',
            'CMS_HOST_BOOTSTRAP_CACHE=/cms-host/bootstrap-cache',
        ])
        ->and(array_slice($docker, -5))->toBe([DevImage::IMAGE, 'php', 'tools/bin/dev-image-entry.php', 'vendor/bin/pest', '--testsuite=Browser'])
        ->and(in_array('--tty', $docker, true))->toBeFalse()
        ->and(is_dir($run['home'].'/.pest'))->toBeTrue();
});

it('mounts nothing but the checkout itself for the main checkout, whose .git it holds', function (): void {
    ['main' => $main] = devImageCheckouts();

    $run = runDevImageScript($main, 'dev-image.php', ['--', 'true'], healthyServices());

    expect($run['exitCode'])->toBe(0)
        ->and(optionValues(dockerRun($run['calls']), '--volume'))->toBe([
            $main.':'.$main,
            ...array_map(static fn (CheckoutVolume $volume): string => $volume->name.':'.$volume->mountPoint(), CheckoutVolume::all($main)),
            $main.'/'.VolumeKind::BootstrapCache->directory().':/cms-host/bootstrap-cache:ro',
            $run['home'].'/.pest:/tmp/.pest',
        ]);
});

it('never starts, recreates or stops the shared services', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], healthyServices());
    $compose = array_values(array_filter($run['calls'], static fn (array $call): bool => $call[0] === 'compose'));

    expect($compose)->toHaveCount(1);

    foreach (['up', 'start', 'restart', 'create', 'stop', 'down', 'exec'] as $verb) {
        expect(in_array($verb, $compose[0], true))->toBeFalse();
    }
});

it('passes on TERM and the base of the change for mutation on changed files, and no other host variable', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], healthyServices(), environment: ['TERM' => 'xterm-256color', 'CMS_CI_BASE_REF' => 'HEAD~2', 'DB_HOST' => '127.0.0.1', 'SECRET_TOKEN' => 'x']);
    $environment = optionValues(dockerRun($run['calls']), '--env');

    expect($environment)->toContain('TERM=xterm-256color', 'CMS_CI_BASE_REF=HEAD~2', 'DB_HOST=postgres')
        ->and($environment)->not->toContain('DB_HOST=127.0.0.1')
        ->and(implode("\n", $environment))->not->toContain('SECRET_TOKEN');
});

it('makes the missing volumes with the checkout\'s label and hands them to the host user before the run', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();
    [$modules, $cache, $bootstrap] = array_map(static fn (CheckoutVolume $volume): string => $volume->name, CheckoutVolume::all($worktree));

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], healthyServices(), volumeExists: false);
    $calls = array_slice($run['calls'], 1);

    expect($run['exitCode'])->toBe(0)
        ->and(array_slice($calls, 0, 6))->toBe([
            ['volume', 'inspect', $modules],
            ['volume', 'create', '--label', CheckoutVolume::LABEL.'='.$worktree, $modules],
            ['volume', 'inspect', $cache],
            ['volume', 'create', '--label', CheckoutVolume::LABEL.'='.$worktree, $cache],
            ['volume', 'inspect', $bootstrap],
            ['volume', 'create', '--label', CheckoutVolume::LABEL.'='.$worktree, $bootstrap],
        ])
        ->and($calls[6])->toBe([
            'run', '--rm', '--entrypoint', '', '--user', '0:0',
            '--volume', $modules.':/volumes/node-modules',
            '--volume', $cache.':/volumes/cache',
            '--volume', $bootstrap.':/volumes/bootstrap-cache',
            DevImage::IMAGE,
            'chown', devImageHostId('-u').':'.devImageHostId('-g'), '/volumes/node-modules', '/volumes/cache', '/volumes/bootstrap-cache',
        ])
        ->and($calls[7][0])->toBe('run')
        ->and(optionValues($calls[7], '--user'))->toBe([devImageHostId('-u').':'.devImageHostId('-g')]);
});

it('neither makes nor claims volumes that exist', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], healthyServices());
    $volumeCalls = array_values(array_filter($run['calls'], static fn (array $call): bool => $call[0] === 'volume'));

    expect(array_column($volumeCalls, 1))->toBe(['inspect', 'inspect', 'inspect'])
        ->and(array_filter($run['calls'], static fn (array $call): bool => in_array('0:0', $call, true)))->toBe([]);
});

it('fails with the fix and runs nothing when a shared service is down, unhealthy or missing', function (string $ps, string $problem): void {
    ['main' => $main, 'worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], $ps);

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['calls'][0][0])->toBe('compose')
        ->and($run['errors'])->toContain('The shared services are not running: '.$problem.'.')
        ->and($run['errors'])->toContain("cd {$main} && composer services:up");
})->with([
    'none created' => ['', 'postgres (not created), valkey (not created)'],
    'Postgres exited' => [composeContainer('postgres', 'exited', '')."\n".composeContainer('valkey'), 'postgres (exited)'],
    'Valkey unhealthy' => [composeContainer('postgres')."\n".composeContainer('valkey', 'running', 'unhealthy'), 'valkey (running, unhealthy)'],
    'Valkey still starting' => [composeContainer('postgres')."\n".composeContainer('valkey', 'running', 'starting'), 'valkey (running, starting)'],
]);

it('fails with the fix and runs nothing when docker compose cannot list the services', function (): void {
    ['main' => $main, 'worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], '', psExit: 1);

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['errors'])->toContain('Cannot list the shared services with docker compose: Cannot connect to the Docker daemon')
        ->and($run['errors'])->toContain("cd {$main} && composer services:up");
});

it('exits 2 without a command and calls no docker', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--'], healthyServices());

    expect($run['exitCode'])->toBe(2)
        ->and($run['errors'])->toContain('Usage: php tools/bin/dev-image.php -- <command>')
        ->and($run['calls'])->toBe([]);
});

it('runs composer check again in the dev image for this checkout, with the report\'s directory mounted at its own path', function (): void {
    $reports = (string) realpath(ScratchDirectory::make('cbox-cms-dev-image-report-'));
    $root = (string) realpath(Phpstan::root());

    $run = runDevImageScript($root, 'check.php', ['--brief', '--report='.$reports.'/check.json'], healthyServices(), runExit: 1);
    $docker = dockerRun($run['calls']);

    expect($run['exitCode'])->toBe(1)
        ->and(optionValues($docker, '--workdir'))->toBe([$root])
        ->and(optionValues($docker, '--volume'))->toContain($root.':'.$root, $reports.':'.$reports)
        ->and(array_slice($docker, -7))->toBe([DevImage::IMAGE, 'php', 'tools/bin/dev-image-entry.php', 'php', 'tools/bin/check.php', '--brief', '--report='.$reports.'/check.json'])
        ->and($run['output'])->toBe('');
});

it('checks the options of composer check on the host and exits 2 on an unknown one before it calls docker', function (): void {
    $run = runDevImageScript(Phpstan::root(), 'check.php', ['--bogus'], healthyServices());

    expect($run['exitCode'])->toBe(2)
        ->and($run['errors'])->toContain('Unknown option --bogus')
        ->and($run['calls'])->toBe([]);
});

/**
 * A worktree that has what the served panel needs: an application key in workbench/.env and the
 * panel's build manifest.
 *
 * @return array{main: string, worktree: string}
 */
function servableCheckouts(bool $key = true, bool $build = true): array
{
    $checkouts = devImageCheckouts();

    if ($key) {
        ScratchDirectory::write($checkouts['worktree'].'/'.WorkbenchEnvironment::FILE, "APP_NAME=\"Cbox CMS\"\nAPP_KEY=base64:".base64_encode(str_repeat('k', 32))."\n");
    }

    if ($build) {
        ScratchDirectory::write($checkouts['worktree'].'/'.WorkbenchServe::PANEL_MANIFEST, '{}');
    }

    return $checkouts;
}

it('serves the workbench from a worktree in the dev image, with the server\'s port published on the host\'s 127.0.0.1:8080, on the services\' network', function (): void {
    ['main' => $main, 'worktree' => $worktree] = servableCheckouts();

    $run = runDevImageScript($worktree, 'workbench-serve.php', [], healthyServices(), runExit: 130);
    $docker = dockerRun($run['calls']);

    expect($run['exitCode'])->toBe(130)
        ->and($run['calls'][0])->toBe(['compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'ps', '--all', '--format', 'json', 'postgres', 'valkey'])
        ->and(optionValues($docker, '--publish'))->toBe(['127.0.0.1:8080:8080'])
        ->and(optionValues($docker, '--network'))->toBe(['scratch_default'])
        ->and(optionValues($docker, '--workdir'))->toBe([$worktree])
        ->and(optionValues($docker, '--user'))->toBe([devImageHostId('-u').':'.devImageHostId('-g')])
        ->and(optionValues($docker, '--volume'))->toContain($worktree.':'.$worktree)
        ->and(optionValues($docker, '--env'))->toContain('DB_HOST=postgres', 'DB_PORT=5432', 'REDIS_HOST=valkey', 'REDIS_PORT=6379')
        ->and(array_slice($docker, -9))->toBe([DevImage::IMAGE, 'php', 'tools/bin/dev-image-entry.php', 'php', 'vendor/bin/testbench', 'serve', '--host=0.0.0.0', '--port=8080', '--no-interaction'])
        ->and($run['output'])->toContain('http://127.0.0.1:8080/cms');
});

it('publishes another host port with --port, always on 127.0.0.1 and to the server\'s port 8080', function (): void {
    ['worktree' => $worktree] = servableCheckouts();

    $run = runDevImageScript($worktree, 'workbench-serve.php', ['--port=9001'], healthyServices());
    $docker = dockerRun($run['calls']);

    expect($run['exitCode'])->toBe(0)
        ->and(optionValues($docker, '--publish'))->toBe(['127.0.0.1:9001:8080'])
        ->and(array_slice($docker, -2))->toBe(['--port=8080', '--no-interaction'])
        ->and($run['output'])->toContain('http://127.0.0.1:9001/cms');
});

it('publishes no port for any other run in the dev image', function (): void {
    ['worktree' => $worktree] = devImageCheckouts();

    $run = runDevImageScript($worktree, 'dev-image.php', ['--', 'true'], healthyServices());

    expect(optionValues(dockerRun($run['calls']), '--publish'))->toBe([]);
});

it('refuses a port that is no TCP port and an unknown argument with exit 2, and calls no docker', function (string $argument, string $message): void {
    ['worktree' => $worktree] = servableCheckouts();

    $run = runDevImageScript($worktree, 'workbench-serve.php', [$argument], healthyServices());

    expect($run['exitCode'])->toBe(2)
        ->and($run['errors'])->toContain($message)
        ->and($run['errors'])->toContain(WorkbenchServeOptions::USAGE)
        ->and($run['calls'])->toBe([]);
})->with([
    'zero' => ['--port=0', 'The port is a TCP port from 1 to 65535, not [0].'],
    'too high' => ['--port=65536', 'The port is a TCP port from 1 to 65535, not [65536].'],
    'not a number' => ['--port=http', 'The port is a TCP port from 1 to 65535, not [http].'],
    'unknown' => ['--host=0.0.0.0', 'Unknown argument [--host=0.0.0.0].'],
]);

it('says how to make the application key and the panel\'s build when they are missing, exits 1 and calls no docker', function (bool $key, bool $build, array $fixes): void {
    ['worktree' => $worktree] = servableCheckouts($key, $build);

    $run = runDevImageScript($worktree, 'workbench-serve.php', [], healthyServices());

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toBe([]);

    foreach ($fixes as $fix) {
        expect($run['errors'])->toContain($fix);
    }
})->with([
    'no key' => [false, true, ['workbench/.env has no APP_KEY', 'Run composer dev:prepare']],
    'no build' => [true, false, ['The panel has no build', 'Run composer panel:build']],
    'neither' => [false, false, ['workbench/.env has no APP_KEY', 'The panel has no build']],
]);

it('says to copy workbench/.env again when Testbench\'s application holds another copy, exits 1 and calls no docker', function (): void {
    ['worktree' => $worktree] = servableCheckouts();
    ScratchDirectory::write($worktree.'/'.WorkbenchEnvironment::APPLICATION_FILE, "APP_KEY=\n");

    $run = runDevImageScript($worktree, 'workbench-serve.php', [], healthyServices());

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toBe([])
        ->and($run['errors'])->toContain(WorkbenchEnvironment::APPLICATION_FILE.' is not what workbench/.env holds')
        ->and($run['errors'])->toContain('Run composer dev:prepare, which copies it.');
});

it('fails with the fix and serves nothing when the shared services do not run', function (): void {
    ['main' => $main, 'worktree' => $worktree] = servableCheckouts();

    $run = runDevImageScript($worktree, 'workbench-serve.php', [], composeContainer('postgres', 'exited', '')."\n".composeContainer('valkey'));

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['errors'])->toContain('The shared services are not running: postgres (exited).')
        ->and($run['errors'])->toContain("cd {$main} && composer services:up");
});

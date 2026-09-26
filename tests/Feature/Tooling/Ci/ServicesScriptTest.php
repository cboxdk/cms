<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tooling\Services\Boundary\GitCheckout;
use Cbox\Cms\Tooling\Services\Domain\Checkout;
use Cbox\Cms\Tooling\Services\Domain\HostUser;
use Cbox\Cms\Tooling\Services\Domain\ServicesAction;
use Cbox\Cms\Tooling\Services\Domain\ServicesPlan;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * composer services:up and services:down run tools/bin/services.php (PROGRESS.md, "Beslutninger
 * fra Sylvester", parallel worktrees). Whichever checkout runs it, docker compose gets the main
 * checkout's compose.yaml and the main checkout as the project directory, so no container mounts
 * a worktree. From a linked worktree, up starts only Postgres and Valkey without recreating
 * anything, and down refuses. The script runs here on a scratch repository with a linked
 * worktree and a fake docker on the PATH that records how it was called.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A scratch repository with a compose.yaml and a linked worktree of it.
 *
 * @return array{main: string, worktree: string}
 */
function servicesCheckouts(): array
{
    $repository = ScratchRepository::make('cbox-cms-services-main-');
    $repository->write('compose.yaml', "name: scratch\n")->commit('compose');
    $worktree = ScratchDirectory::make('cbox-cms-services-worktree-').'/probe';
    $repository->git('worktree', 'add', '--quiet', '--detach', $worktree);

    return ['main' => $repository->root, 'worktree' => (string) realpath($worktree)];
}

/**
 * Runs tools/bin/services.php of this checkout in a directory, with a fake docker first on the
 * PATH that appends CMS_UID, CMS_GID and its arguments to a log, one per line, and exits with
 * $exitCode.
 *
 * @return array{exitCode: int|null, output: string, errors: string, calls: list<list<string>>}
 */
function runServicesScript(string $directory, string $action, int $exitCode = 0): array
{
    $bin = ScratchDirectory::make('cbox-cms-fake-docker-');
    $log = $bin.'/calls.log';
    ScratchDirectory::write($bin.'/docker', <<<'SH'
        #!/bin/sh
        {
            printf 'CMS_UID=%s\nCMS_GID=%s\n' "${CMS_UID:-}" "${CMS_GID:-}"
            for argument in "$@"; do printf '%s\n' "$argument"; done
            printf '%s\n' '--end-of-call--'
        } >> "$FAKE_DOCKER_LOG"
        exit "${FAKE_DOCKER_EXIT:-0}"
        SH);
    chmod($bin.'/docker', 0o755);

    $process = new Process(
        [PHP_BINARY, Phpstan::root().'/tools/bin/services.php', $action],
        $directory,
        ['PATH' => $bin.':'.getenv('PATH'), 'FAKE_DOCKER_LOG' => $log, 'FAKE_DOCKER_EXIT' => (string) $exitCode],
    );
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

    return ['exitCode' => $process->getExitCode(), 'output' => $process->getOutput(), 'errors' => $process->getErrorOutput(), 'calls' => $calls];
}

function hostId(string $option): string
{
    $process = new Process(['id', $option]);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * The value that follows an option in a docker compose call.
 *
 * @param  list<string>  $call
 */
function composeOption(array $call, string $option): ?string
{
    $index = array_search($option, $call, true);

    return is_int($index) ? $call[$index + 1] ?? null : null;
}

/**
 * The service names a `docker compose ... up` call names after its options, empty when it
 * starts every service.
 *
 * @param  list<string>  $call
 * @return list<string>
 */
function composeUpServices(array $call): array
{
    $index = array_search('up', $call, true);

    return is_int($index)
        ? array_values(array_filter(array_slice($call, $index + 1), static fn (string $argument): bool => ! str_starts_with($argument, '-')))
        : [];
}

it('runs up -d --wait and then the init script with the main checkout\'s compose.yaml from the main checkout', function (): void {
    ['main' => $main] = servicesCheckouts();
    $user = 'CMS_UID='.hostId('-u');
    $group = 'CMS_GID='.hostId('-g');

    $run = runServicesScript($main, 'up');

    expect($run['exitCode'])->toBe(0)
        ->and($run['calls'])->toHaveCount(2)
        ->and($run['calls'][0])->toBe([$user, $group, 'compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'up', '-d', '--wait'])
        ->and($run['calls'][1])->toBe([$user, $group, 'compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'exec', '-T', 'postgres', '/docker-entrypoint-initdb.d/10-cms.sh'])
        ->and($run['output'])->not->toContain('linked worktree');
});

it('stops the services with the main checkout\'s compose.yaml from the main checkout', function (): void {
    ['main' => $main] = servicesCheckouts();

    $run = runServicesScript($main, 'down');

    expect($run['exitCode'])->toBe(0)
        ->and(array_map(static fn (array $call): array => array_slice($call, 2), $run['calls']))
        ->toBe([['compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'down']]);
});

it('starts only Postgres and Valkey from a linked worktree, without recreating a container or mounting the worktree, and says that php mounts the main checkout', function (string $subdirectory): void {
    ['main' => $main, 'worktree' => $worktree] = servicesCheckouts();
    ScratchDirectory::write($worktree.$subdirectory.'/.keep');

    $run = runServicesScript($worktree.$subdirectory, 'up');
    $calls = array_map(static fn (array $call): array => array_slice($call, 2), $run['calls']);

    expect($run['exitCode'])->toBe(0)
        ->and($calls)->toBe([
            ['compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'up', '-d', '--wait', '--no-recreate', 'postgres', 'valkey'],
            ['compose', '--file', $main.'/compose.yaml', '--project-directory', $main, 'exec', '-T', 'postgres', '/docker-entrypoint-initdb.d/10-cms.sh'],
        ])
        ->and(composeUpServices($calls[0]))->toBe(['postgres', 'valkey'])
        ->and($run['output'])->toContain("The php container mounts the main checkout, {$main}:/var/www/html")
        ->and($run['output'])->toContain('`docker compose exec php ...` tests the main checkout\'s code, not this worktree\'s')
        ->and($run['output'])->toContain("{$worktree} is a linked worktree of {$main}");

    foreach ($calls as $call) {
        expect(implode("\n", $call))->not->toContain($worktree);
    }
})->with(['the worktree root' => '', 'a directory below it' => '/nested/directory']);

it('refuses composer services:down in a linked worktree with exit 1 and calls no docker', function (): void {
    ['main' => $main, 'worktree' => $worktree] = servicesCheckouts();

    $run = runServicesScript($worktree, 'down');

    expect($run['exitCode'])->toBe(1)
        ->and($run['calls'])->toBe([])
        ->and($run['errors'])->toContain("composer services:down does not run in the linked worktree {$worktree}: the services are shared")
        ->and($run['errors'])->toContain("cd {$main} && composer services:down");
});

it('stops at the first docker command that fails and exits with its code', function (): void {
    ['worktree' => $worktree] = servicesCheckouts();

    $run = runServicesScript($worktree, 'up', 3);

    expect($run['exitCode'])->toBe(3)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->not->toContain('The php container mounts the main checkout');
});

it('exits 2 without up or down, and 1 outside a git checkout, before it calls docker', function (): void {
    ['main' => $main] = servicesCheckouts();
    $outside = ScratchDirectory::make('cbox-cms-services-outside-');

    $usage = runServicesScript($main, 'restart');
    $nowhere = runServicesScript($outside, 'up');

    expect($usage['exitCode'])->toBe(2)
        ->and($usage['errors'])->toContain('Usage: php tools/bin/services.php up|down')
        ->and($usage['calls'])->toBe([])
        ->and($nowhere['exitCode'])->toBe(1)
        ->and($nowhere['errors'])->toContain("Cannot find the git checkout of {$outside}")
        ->and($nowhere['calls'])->toBe([]);
});

it('resolves the main checkout from the common git directory, for the main checkout and a linked worktree', function (): void {
    ['main' => $main, 'worktree' => $worktree] = servicesCheckouts();

    expect(GitCheckout::resolve($main))->toEqual(new Checkout($main, $main))
        ->and(GitCheckout::resolve($main)->isLinkedWorktree())->toBeFalse()
        ->and(GitCheckout::resolve($worktree))->toEqual(new Checkout($worktree, $main))
        ->and(GitCheckout::resolve($worktree)->isLinkedWorktree())->toBeTrue();
});

it('plans no command from a linked worktree that names the worktree or starts php', function (ServicesAction $action): void {
    $plan = ServicesPlan::for($action, new Checkout('/work/trees/probe', '/work/main'), new HostUser(501, 20));

    foreach ($plan->commands as $command) {
        expect(implode("\n", $command))->not->toContain('/work/trees/probe')
            ->and(composeOption($command, '--file'))->toBe('/work/main/compose.yaml')
            ->and(composeOption($command, '--project-directory'))->toBe('/work/main');

        if (in_array('up', $command, true)) {
            expect(composeUpServices($command))->toBe(ServicesPlan::SHARED_SERVICES)
                ->and($command)->toContain('--no-recreate')
                ->and(in_array('php', $command, true))->toBeFalse();
        }
    }

    expect($plan->environment)->toBe(['CMS_UID' => '501', 'CMS_GID' => '20']);
})->with(ServicesAction::cases());

it('takes only absolute checkout roots without a trailing slash', function (string $root): void {
    expect(static fn (): Checkout => new Checkout($root, '/work/main'))->toThrow(InvalidArgumentException::class);
})->with(['relative' => 'work/main', 'trailing slash' => '/work/main/', 'empty' => '']);

it('reports a directory git cannot resolve as unexpected', function (): void {
    expect(static fn (): Checkout => GitCheckout::resolve(ScratchDirectory::make('cbox-cms-services-nogit-')))
        ->toThrow(UnexpectedValueException::class, 'Cannot find the git checkout of');
});

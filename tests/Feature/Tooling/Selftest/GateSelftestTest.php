<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\DevImage\Domain\CheckoutVolume;
use Cbox\Cms\Tooling\DevImage\Domain\VolumeKind;
use Cbox\Cms\Tooling\Selftest\Adapter\GateSelftest;
use Cbox\Cms\Tooling\Selftest\Domain\Plants;
use RuntimeException;

/*
 * The orchestration of `composer check:selftest`, with git, Composer, npm and `composer check`
 * scripted, so every way it must fail is shown quickly. The real run is `composer
 * check:selftest` itself.
 */

/**
 * @return array{exitCode: int, output: string, runner: ScriptedProcessRunner}
 */
function runSelftest(FakeSelftestWorld $world): array
{
    $runner = new ScriptedProcessRunner($world->outcome(...));
    $stream = fopen('php://memory', 'w+') ?: throw new RuntimeException('No memory stream.');
    $exitCode = new GateSelftest($runner, ['composer'], $stream, 'php')->run('/srv/main');
    rewind($stream);

    return ['exitCode' => $exitCode, 'output' => (string) stream_get_contents($stream), 'runner' => $runner];
}

function selftestBase(FakeSelftestWorld $world): string
{
    return dirname($world->worktree ?? throw new RuntimeException('No worktree was added.'));
}

/**
 * The temporary directory the run reported that it made. Selftests in other checkouts make
 * directories with the same prefix at the same time, so a test asserts on this one only.
 */
function selftestTemporaryDirectory(string $output): string
{
    if (preg_match('/^'.preg_quote(GateSelftest::TEMPORARY_DIRECTORY, '/').'(.+)$/m', $output, $match) !== 1) {
        throw new RuntimeException("The selftest did not report its temporary directory:\n{$output}");
    }

    return $match[1];
}

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('installs a worktree of HEAD in the temporary directory, runs composer check there and passes when every plant is caught', function (): void {
    $world = new FakeSelftestWorld;

    ['exitCode' => $exitCode, 'output' => $output, 'runner' => $runner] = runSelftest($world);
    $base = selftestBase($world);
    $worktree = $base.'/laravel-cms';
    $commands = $runner->commandLines();

    expect($exitCode)->toBe(0)
        ->and(dirname($base))->toBe(realpath(sys_get_temp_dir()))
        ->and(basename($base))->toStartWith(GateSelftest::PREFIX)
        ->and(selftestTemporaryDirectory($output))->toBe($base)
        ->and($commands[0])->toBe('git rev-parse HEAD')
        ->and($commands[1])->toBe("git worktree add --detach {$worktree} 0123abc")
        ->and($commands[2])->toBe('composer install --no-interaction --no-progress')
        ->and($commands[3])->toBe('npm ci --no-audit --no-fund')
        ->and($commands[4])->toBe("composer check -- --report={$base}/check-report.json --brief")
        ->and(array_slice($commands, 5))->toBe([
            'php /srv/main/tools/bin/drop-test-database.php '.$worktree,
            "git worktree remove --force {$worktree}",
            'docker volume rm --force '.implode(' ', array_map(static fn (CheckoutVolume $volume): string => $volume->name, CheckoutVolume::all($worktree))),
            'git worktree prune',
            'git worktree list --porcelain',
        ])
        ->and($output)->toContain('Removed the dev image volumes of the worktree: '.CheckoutVolume::for(VolumeKind::NodeModules, $worktree)->name.', ')
        ->and($runner->calls[5]->directory)->toBe('/srv/main')
        ->and($world->droppedWhileExisting)->toBe([$worktree])
        ->and($output)->toContain("Dropped the test database cms_test_0123456789ab of {$worktree}.")
        ->and($runner->calls[2]->directory)->toBe($worktree)
        ->and($runner->calls[3]->environment)->toHaveKey('PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD')
        ->and($world->checkRanInWorktree)->toBeTrue()
        ->and($output)->toContain("autoload Cbox\\Cms\\Core\\ = {$worktree}/packages/core/src (inside the worktree)")
        ->and($output)->not->toContain('Psr\\Log')
        ->and(substr_count($output, '  caught '))->toBe(count(Plants::all()))
        ->and($output)->toContain("{$base} is removed.", 'Selftest passed')
        ->and(file_exists($base))->toBeFalse();
});

it('stops before planting when the autoloader maps a Cbox\\Cms namespace outside the worktree, and still removes it', function (): void {
    $world = new FakeSelftestWorld;
    $world->coreTarget = (string) realpath(ScratchDirectory::make());

    ['exitCode' => $exitCode, 'output' => $output, 'runner' => $runner] = runSelftest($world);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('(OUTSIDE the worktree)', "The autoloader maps Cbox\\Cms\\Core\\ to {$world->coreTarget}, outside the worktree.", 'Selftest failed.')
        ->and(implode("\n", $runner->commandLines()))->not->toContain('composer check')
        ->and($world->droppedWhileExisting)->toBe([selftestBase($world).'/laravel-cms'])
        ->and(file_exists(selftestBase($world)))->toBeFalse();
});

it('fails when a gate misses its planted violation, and prints that step\'s output', function (): void {
    $world = new FakeSelftestWorld;
    $world->missedSteps = ['Rector'];

    ['exitCode' => $exitCode, 'output' => $output] = runSelftest($world);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/MISSED  gate 2 Rector/')
        ->and($output)->toContain('Rector did not fail, its status is pass', 'Selftest failed.')
        ->and(substr_count($output, '  caught '))->toBe(count(Plants::all()) - 1)
        ->and(file_exists(selftestBase($world)))->toBeFalse();
});

it('fails when composer check passes with the violations planted, and still drops the worktree\'s test database', function (): void {
    $world = new FakeSelftestWorld;
    $world->checkExitCode = 0;

    ['exitCode' => $exitCode, 'output' => $output, 'runner' => $runner] = runSelftest($world);
    $worktree = selftestBase($world).'/laravel-cms';

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('composer check passed with the violations planted.', 'Selftest failed.')
        ->and($runner->commandLines())->toContain('php /srv/main/tools/bin/drop-test-database.php '.$worktree)
        ->and($world->droppedWhileExisting)->toBe([$worktree])
        ->and($output)->toContain("Dropped the test database cms_test_0123456789ab of {$worktree}.");
});

it('fails when the worktree\'s test database cannot be dropped, and still removes the worktree', function (): void {
    $world = new FakeSelftestWorld;
    $world->dropExitCode = 1;

    ['exitCode' => $exitCode, 'output' => $output] = runSelftest($world);

    expect($exitCode)->toBe(1)
        ->and(substr_count($output, '  caught '))->toBe(count(Plants::all()))
        ->and($output)->toContain('The owner role cms_owner has no CREATEDB.', 'Could not drop the test database of the worktree; exit code 1.', 'Selftest failed.')
        ->and($output)->not->toContain('Selftest passed')
        ->and(file_exists(selftestBase($world)))->toBeFalse();
});

it('fails when git still lists the worktree after the clean-up', function (): void {
    $world = new FakeSelftestWorld;
    $world->stillListed = true;

    ['exitCode' => $exitCode, 'output' => $output] = runSelftest($world);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('git still lists', 'Selftest failed.')
        ->and($output)->not->toContain('Selftest passed');
});

it('removes its own temporary directory when a step before the worktree fails, and leaves another checkout\'s selftest directory alone', function (): void {
    $foreign = null;
    $runner = new ScriptedProcessRunner(static function (array $command) use (&$foreign): ProcessOutcome {
        if ($command !== ['git', 'rev-parse', 'HEAD']) {
            return new ProcessOutcome(0, '', 0.0);
        }

        // A check:selftest in another checkout makes its directory while this run is under way.
        $foreign = ScratchDirectory::make(GateSelftest::PREFIX);

        return new ProcessOutcome(128, "fatal: not a git repository\n", 0.0);
    });
    $stream = fopen('php://memory', 'w+') ?: throw new RuntimeException('No memory stream.');

    $exitCode = new GateSelftest($runner, ['composer'], $stream, 'php')->run('/srv/main');
    rewind($stream);
    $output = (string) stream_get_contents($stream);
    $base = selftestTemporaryDirectory($output);
    $foreign ??= throw new RuntimeException('The run never asked for git rev-parse HEAD.');

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $runner->commandLines()))->not->toContain('drop-test-database')
        ->and($output)->toContain('git rev-parse HEAD failed with exit code 128', 'fatal: not a git repository', "{$base} is removed.")
        ->and(dirname($base))->toBe(realpath(sys_get_temp_dir()))
        ->and(basename($base))->toStartWith(GateSelftest::PREFIX)
        ->and(file_exists($base))->toBeFalse()
        ->and($foreign)->not->toBe($base)
        ->and(is_dir($foreign))->toBeTrue();
});

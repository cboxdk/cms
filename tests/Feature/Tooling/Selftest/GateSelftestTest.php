<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Selftest\Adapter\GateSelftest;
use Cbox\Cms\Tooling\Selftest\Domain\Plant;
use Cbox\Cms\Tooling\Selftest\Domain\Plants;
use RuntimeException;

/*
 * The orchestration of `composer check:selftest`, with git, Composer, npm and `composer check`
 * scripted, so every way it must fail is shown quickly. The real run is `composer
 * check:selftest` itself.
 */

final class FakeSelftestWorld
{
    public ?string $worktree = null;

    public string $vendorTarget = '../../packages/core';

    /** @var list<string> steps whose planted files `composer check` does not report */
    public array $missedSteps = [];

    public int $checkExitCode = 1;

    public bool $stillListed = false;

    public bool $checkRanInWorktree = false;

    public int $dropExitCode = 0;

    /** @var list<string> the worktrees whose test database was dropped while they still existed */
    public array $droppedWhileExisting = [];

    /**
     * @param  list<string>  $command
     */
    public function outcome(array $command, string $directory): ProcessOutcome
    {
        return match (true) {
            $command === ['git', 'rev-parse', 'HEAD'] => new ProcessOutcome(0, "0123abc\n", 0.0),
            array_slice($command, 0, 3) === ['git', 'worktree', 'add'] => $this->addWorktree($command[4]),
            array_slice($command, 0, 2) === ['composer', 'check'] => $this->check($command, $directory),
            array_slice($command, 0, 3) === ['git', 'worktree', 'remove'] => $this->removeWorktree($command[4]),
            array_slice($command, 0, 2) === ['php', '/srv/main/'.GateSelftest::DROP_DATABASE] => $this->drop($command[2]),
            $command === ['git', 'worktree', 'list', '--porcelain'] => new ProcessOutcome(0, "worktree /srv/main\nHEAD 0123abc\n\n".($this->stillListed ? "worktree {$this->worktree}\n" : ''), 0.0),
            default => new ProcessOutcome(0, '', 0.1),
        };
    }

    private function addWorktree(string $path): ProcessOutcome
    {
        ScratchDirectory::write($path.'/workbench/app/Cms/Generated/TypeHandle.php', "<?php\n");
        mkdir($path.'/packages/core', 0o777, true);
        mkdir($path.'/vendor/cboxdk', 0o777, true);
        symlink($this->vendorTarget, $path.'/vendor/cboxdk/cms-core');
        $this->worktree = $path;

        return new ProcessOutcome(0, '', 0.0);
    }

    /**
     * @param  list<string>  $command
     */
    private function check(array $command, string $directory): ProcessOutcome
    {
        $this->checkRanInWorktree = $directory === realpath((string) $this->worktree);
        $reportFile = substr($command[3], strlen('--report='));
        $gates = [];

        foreach (LocalProfile::gates('php', ['composer']) as $gate) {
            $steps = [];

            foreach ($gate->steps as $step) {
                $plants = array_filter(Plants::all(), static fn (Plant $plant): bool => $plant->gate === $gate->number && $plant->step === $step->name);
                $output = implode("\n", array_map(static fn (Plant $plant): string => $plant->path.' '.implode(' ', $plant->markers), $plants));
                $missed = in_array($step->name, $this->missedSteps, true);

                $steps[] = match (true) {
                    ! $step->runs() => StepResult::notRun($step->name, (string) $step->notRunReason),
                    $plants === [] || $missed => StepResult::ran($step->name, new ProcessOutcome(0, 'ok', 1.0)),
                    default => StepResult::ran($step->name, new ProcessOutcome(1, $output, 1.0)),
                };
            }

            $gates[] = new GateResult($gate->number, $gate->title, $steps);
        }

        file_put_contents($reportFile, CheckReportJson::encode(new CheckReport($directory, $gates)));

        return new ProcessOutcome($this->checkExitCode, "Gate 1  Pint and Prettier\n", 1.0);
    }

    private function drop(string $worktree): ProcessOutcome
    {
        if (is_dir($worktree)) {
            $this->droppedWhileExisting[] = $worktree;
        }

        return $this->dropExitCode === 0
            ? new ProcessOutcome(0, "Dropped the test database cms_test_0123456789ab of {$worktree}.\n", 0.1)
            : new ProcessOutcome($this->dropExitCode, "The owner role cms_owner has no CREATEDB.\n", 0.1);
    }

    private function removeWorktree(string $path): ProcessOutcome
    {
        ScratchDirectory::delete($path);

        return new ProcessOutcome(0, '', 0.0);
    }
}

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
            'git worktree prune',
            'git worktree list --porcelain',
        ])
        ->and($runner->calls[5]->directory)->toBe('/srv/main')
        ->and($world->droppedWhileExisting)->toBe([$worktree])
        ->and($output)->toContain("Dropped the test database cms_test_0123456789ab of {$worktree}.")
        ->and($runner->calls[2]->directory)->toBe($worktree)
        ->and($runner->calls[3]->environment)->toHaveKey('PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD')
        ->and($world->checkRanInWorktree)->toBeTrue()
        ->and($output)->toContain("realpath vendor/cboxdk/cms-core = {$worktree}/packages/core (inside the worktree)")
        ->and(substr_count($output, '  caught '))->toBe(count(Plants::all()))
        ->and($output)->toContain("{$base} is removed.", 'Selftest passed')
        ->and(file_exists($base))->toBeFalse();
});

it('stops before planting when vendor/cboxdk resolves outside the worktree, and still removes it', function (): void {
    $world = new FakeSelftestWorld;
    $world->vendorTarget = ScratchDirectory::make();

    ['exitCode' => $exitCode, 'output' => $output, 'runner' => $runner] = runSelftest($world);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('(OUTSIDE the worktree)', 'vendor/cboxdk/cms-core resolves to', 'Selftest failed.')
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

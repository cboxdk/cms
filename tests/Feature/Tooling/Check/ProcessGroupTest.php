<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\Processes;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Symfony\Component\Process\Process;

/*
 * A step in a process group of its own (M0-T46): SymfonyProcessRunner starts the command through
 * tools/bin/process-group.php as the leader of a new group and kills what is left of the group
 * when the command ends, so nothing it started outlives the step or holds its output open.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * The first process id the command printed.
 */
function printedPid(string $output): int
{
    return preg_match('/^(\d+)$/m', $output, $match) === 1 ? (int) $match[1] : 0;
}

it('runs the command as the leader of a new process group, found in PATH as a shell finds it', function (): void {
    $process = new Process([PHP_BINARY, 'tools/bin/process-group.php', 'sh', '-c', 'ps -o pid=,pgid= -p $$; ps -o pgid= -p $PPID'], Phpstan::root());
    $process->run();
    [$own, $parent] = array_map(trim(...), explode("\n", trim($process->getOutput())));
    [$pid, $group] = preg_split('/\s+/', $own) ?: [];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($pid)->toBe($group)
        ->and($parent)->not->toBe($group);
});

it('refuses to run without a command, and a program that is not in PATH', function (): void {
    $none = new Process([PHP_BINARY, 'tools/bin/process-group.php'], Phpstan::root());
    $none->run();
    $missing = new Process([PHP_BINARY, 'tools/bin/process-group.php', 'cbox-cms-no-such-program'], Phpstan::root());
    $missing->run();

    expect($none->getExitCode())->toBe(2)
        ->and($none->getErrorOutput())->toContain('Usage: php tools/bin/process-group.php <program>')
        ->and($missing->getExitCode())->toBe(127)
        ->and($missing->getErrorOutput())->toContain('cbox-cms-no-such-program is not in PATH');
});

it('ends the step when the command exits although a process it started holds the output open, and stops that process', function (): void {
    $started = hrtime(true);
    $outcome = new SymfonyProcessRunner(30.0)->run(['sh', '-c', 'sleep 60 & echo $!; exit 3'], Phpstan::root(), ownProcessGroup: true);
    $sleep = printedPid($outcome->output);

    expect($outcome->exitCode)->toBe(3)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(SymfonyProcessRunner::TERM_GRACE_SECONDS)
        ->and($sleep)->toBeGreaterThan(0)
        ->and(Processes::running([$sleep]))->toBe([])
        ->and($outcome->output)->toContain('they were stopped with SIGTERM');
});

it('kills a process that ignores SIGTERM after the grace time', function (): void {
    $outcome = new SymfonyProcessRunner(30.0)->run(
        ['sh', '-c', '(trap "" TERM; exec sleep 60) & echo $!'],
        Phpstan::root(),
        ownProcessGroup: true,
    );
    $sleep = printedPid($outcome->output);

    expect($outcome->exitCode)->toBe(0)
        ->and($outcome->seconds)->toBeGreaterThanOrEqual(SymfonyProcessRunner::TERM_GRACE_SECONDS)
        ->and($sleep)->toBeGreaterThan(0)
        ->and(Processes::running([$sleep]))->toBe([])
        ->and($outcome->output)->toContain('they were killed with SIGKILL');
});

it('leaves the processes of a command that is not in a group of its own alone', function (): void {
    $outcome = new SymfonyProcessRunner(30.0)->run(['sh', '-c', 'sleep 60 >/dev/null 2>&1 & echo $!'], Phpstan::root());
    $sleep = printedPid($outcome->output);

    try {
        expect($outcome->exitCode)->toBe(0)
            ->and(Processes::running([$sleep]))->toBe([$sleep])
            ->and($outcome->output)->not->toContain('process group');
    } finally {
        posix_kill($sleep, SIGKILL);
    }
});

it('passes a SIGTERM to the runner on to the group and then ends by it', function (): void {
    $pidFile = ScratchDirectory::make().'/sleep.pid';
    $runner = new Process([PHP_BINARY, 'tests/Feature/Tooling/fixtures/grouped-step.php', $pidFile], Phpstan::root(), timeout: 30);
    $runner->start();

    $deadline = hrtime(true) + 10 * 1_000_000_000;

    while (Processes::idsIn($pidFile) === [] && hrtime(true) < $deadline) {
        usleep(20_000);
    }

    $sleep = Processes::idsIn($pidFile);
    $runner->signal(SIGTERM);
    $runner->wait();

    expect($sleep)->toHaveCount(1)
        ->and($runner->getTermSignal())->toBe(SIGTERM)
        ->and($runner->getOutput())->not->toContain('step ended')
        ->and(Processes::running($sleep))->toBe([]);
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\LaravelBootLock;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/*
 * PHPStan's workers boot Laravel for Larastan at the same moment, and each boot writes shared
 * files in Testbench's skeleton. Through a Docker Desktop bind mount a worker then read a manifest
 * that another was replacing, and gate 3 failed with "Laravel framework bootstrap failed" in about
 * one run in three. The testkit's configuration takes a lock just before Larastan's bootstrap and
 * releases it just after (SharedToolConfigTest checks the order). These tests run the two
 * bootstrap files in real processes, as PHPStan does.
 */

/**
 * A PHP process that runs phpstan-boot-lock.php in $workingDirectory, prints "locked", waits for a
 * line on its input, runs phpstan-boot-unlock.php and prints "released".
 */
function bootLockProcess(string $workingDirectory, string $lockDirectory, InputStream $input): Process
{
    $config = dirname(__DIR__, 2).'/config';
    $code = <<<'PHP'
        require $argv[1];
        require $argv[2];
        echo "locked\n";
        fgets(STDIN);
        require $argv[3];
        echo "released\n";
        PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 4).'/vendor/autoload.php', $config.'/phpstan-boot-lock.php', $config.'/phpstan-boot-unlock.php'],
        $workingDirectory,
        ['TMPDIR' => $lockDirectory],
        $input,
        30,
    );
    $process->start();

    return $process;
}

/**
 * Waits until $process has printed $line, within the process's own timeout.
 *
 * This polls the whole output instead of calling waitUntil(). waitUntil() reads the pipes once
 * before it starts calling its callback, and output from that read reaches the buffer without the
 * callback. A child that prints its line just then, as a child that takes the lock the moment the
 * test releases it does, and then waits on its input, prints nothing more, so waitUntil() waited
 * until the timeout with the line already in the buffer.
 */
function waitForOutput(Process $process, string $line): void
{
    while (true) {
        // Ask whether it runs before reading, so the read after it ended includes all it printed.
        $running = $process->isRunning();

        if (str_contains($process->getOutput(), $line)) {
            return;
        }

        if (! $running) {
            throw new RuntimeException("The process ended without printing {$line}: {$process->getErrorOutput()}");
        }

        $process->checkTimeout();
        usleep(1_000);
    }
}

function bootLockDirectory(string $name): string
{
    $directory = sys_get_temp_dir().'/cbox-cms-boot-lock-test-'.getmypid().'-'.$name;

    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException("Could not create {$directory}.");
    }

    return realpath($directory) ?: $directory;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/cbox-cms-boot-lock-test-'.getmypid().'-*') ?: [] as $directory) {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('sees a line that the process prints while the wait is between two reads of its output', function (): void {
    // The child prints the line 200 ms after it starts and then waits on its input, like the lock
    // tests' children. The first read comes before the line, and the process then pauses long enough
    // for the line to arrive before the next read, as a test process does when the machine is busy
    // just as a child takes the lock the test released.
    $input = new InputStream;
    $process = new class([PHP_BINARY, '-r', 'usleep(200_000); echo "locked\\n"; fgets(STDIN);'], null, null, $input, 5) extends Process
    {
        private bool $paused = false;

        public function getOutput(): string
        {
            $output = parent::getOutput();

            if (! $this->paused) {
                $this->paused = true;
                usleep(600_000);
            }

            return $output;
        }
    };
    $process->start();

    waitForOutput($process, 'locked');
    $input->write("go\n");
    $input->close();

    expect($process->wait())->toBe(0)
        ->and($process->getOutput())->toBe("locked\n");
});

it('lets one process at a time through from the lock file to the unlock file', function (): void {
    $workingDirectory = bootLockDirectory('work');
    $lockDirectory = bootLockDirectory('locks');
    $firstInput = new InputStream;
    $secondInput = new InputStream;

    $first = bootLockProcess($workingDirectory, $lockDirectory, $firstInput);
    waitForOutput($first, 'locked');

    $second = bootLockProcess($workingDirectory, $lockDirectory, $secondInput);
    usleep(500_000);

    expect($second->isRunning())->toBeTrue()
        ->and($second->getOutput())->toBe('');

    // Symfony writes a process's input only while the test waits on that process.
    $firstInput->write("go\n");
    $firstInput->close();

    expect($first->wait())->toBe(0)
        ->and($first->getOutput())->toBe("locked\nreleased\n");

    waitForOutput($second, 'locked');
    $secondInput->write("go\n");
    $secondInput->close();

    expect($second->wait())->toBe(0)
        ->and($second->getOutput())->toBe("locked\nreleased\n")
        ->and(glob($lockDirectory.'/*'))->toBe([LaravelBootLock::path($lockDirectory, $workingDirectory)]);
});

it('frees the lock when the process that holds it exits, as when Larastan fails to boot', function (): void {
    $workingDirectory = bootLockDirectory('work');
    $lockDirectory = bootLockDirectory('locks');
    $firstInput = new InputStream;
    $secondInput = new InputStream;

    $first = bootLockProcess($workingDirectory, $lockDirectory, $firstInput);
    waitForOutput($first, 'locked');
    $second = bootLockProcess($workingDirectory, $lockDirectory, $secondInput);

    $first->stop(0);
    waitForOutput($second, 'locked');
    $secondInput->write("go\n");
    $secondInput->close();

    expect($second->wait())->toBe(0);
});

it('keeps one lock per working directory, so PHPStan runs in two repositories do not wait for each other', function (): void {
    $lockDirectory = bootLockDirectory('locks');
    $firstInput = new InputStream;
    $secondInput = new InputStream;

    $first = bootLockProcess(bootLockDirectory('one'), $lockDirectory, $firstInput);
    waitForOutput($first, 'locked');
    $second = bootLockProcess(bootLockDirectory('two'), $lockDirectory, $secondInput);
    waitForOutput($second, 'locked');

    foreach ([$firstInput, $secondInput] as $input) {
        $input->write("go\n");
        $input->close();
    }

    expect($first->wait())->toBe(0)
        ->and($second->wait())->toBe(0)
        ->and(glob($lockDirectory.'/*'))->toHaveCount(2);
});

it('takes the lock once per process and releases only a lock it holds', function (): void {
    $workingDirectory = bootLockDirectory('work');
    $lockDirectory = bootLockDirectory('locks');

    LaravelBootLock::release();
    LaravelBootLock::acquire($lockDirectory, $workingDirectory);
    LaravelBootLock::acquire($lockDirectory, $workingDirectory);

    $input = new InputStream;
    $other = bootLockProcess($workingDirectory, $lockDirectory, $input);
    usleep(300_000);

    expect($other->getOutput())->toBe('');

    LaravelBootLock::release();
    LaravelBootLock::release();
    waitForOutput($other, 'locked');
    $input->write("go\n");
    $input->close();

    expect($other->wait())->toBe(0);
});

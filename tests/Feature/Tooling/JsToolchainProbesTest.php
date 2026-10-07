<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\MarkerScan;
use Cbox\Cms\Tests\Support\JsToolchainLock;
use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Closure;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * The probe files of the JS toolchain tests lie in the workbench's TypeScript, which tsc checks as
 * a whole, and a parallel Pest run, such as the mutation run of gate 5, runs those tests at the
 * same time. A type check that must pass then failed on another process's probe that is meant to
 * fail it (TS2375 under exactOptionalPropertyTypes). Node::withProbe and Node::run keep them apart
 * through JsToolchainLock.
 */

/**
 * A probe that fails the type check, as JsToolchainTest writes it.
 */
const BROKEN_PROBE = "type Options = { label?: string };\n\nexport const options: Options = { label: undefined };\n";

/**
 * The line the process holding the broken probe prints once it runs, before it waits for the lock.
 */
const PROBE_HOLDER_STARTED = 'started';

/**
 * How long the process holding the broken probe may take, waiting for the lock included. Under the
 * parallel suite the exclusive lock is a queue of every test that writes a probe, each holding it
 * for a tool run, so the wait is as long as that queue, which a run of gate 5 saw reach 50 seconds.
 */
const PROBE_HOLDER_TIMEOUT = 300;

/**
 * Starts a PHP process that prints PROBE_HOLDER_STARTED, writes BROKEN_PROBE through
 * Node::withProbe, prints its path and holds it for three seconds, and for as long as another probe
 * lies beside it (at most a minute), so a tool run of this process that is not kept apart from it
 * sees it for its whole run. The process must start within $startWithin seconds; its wait for the
 * exclusive lock is the queue of the other holders and is bounded only by PROBE_HOLDER_TIMEOUT, as
 * a tool run's wait for the lock is.
 */
function holdBrokenProbe(float $startWithin = 30): Process
{
    $code = <<<'PHP'
        fwrite(STDOUT, $argv[3]."\n");

        require $argv[1];

        Cbox\Cms\Tests\Support\Node::withProbe('ts', $argv[2], static function (string $path): void {
            $directory = Cbox\Cms\Tests\Support\Phpstan::root().'/'.Cbox\Cms\Tests\Support\Node::PROBE_DIRECTORY;
            fwrite(STDOUT, $path."\n");
            $start = microtime(true);

            while (microtime(true) - $start < 60) {
                $others = array_diff(glob($directory.'/cms-probe-*') ?: [], [dirname($directory, 3).'/'.$path]);

                if ($others === [] && microtime(true) - $start >= 3) {
                    return;
                }

                usleep(10_000);
            }
        });
        PHP;

    $child = new Process([PHP_BINARY, '-r', $code, Phpstan::root().'/vendor/autoload.php', BROKEN_PROBE, PROBE_HOLDER_STARTED], Phpstan::root(), null, null, PROBE_HOLDER_TIMEOUT);
    $child->start();

    $deadline = microtime(true) + $startWithin;

    while (probeHolderLines($child) === []) {
        if (! $child->isRunning() || microtime(true) > $deadline) {
            $child->stop();

            throw new RuntimeException("The process holding the broken probe did not start:\n".$child->getOutput().$child->getErrorOutput());
        }

        usleep(10_000);
    }

    while (count(probeHolderLines($child)) < 2) {
        if (! $child->isRunning()) {
            throw new RuntimeException("The process holding the broken probe stopped before it wrote the probe:\n".$child->getOutput().$child->getErrorOutput());
        }

        // Throws ProcessTimedOutException, and stops the process, after PROBE_HOLDER_TIMEOUT.
        $child->checkTimeout();
        usleep(10_000);
    }

    return $child;
}

/**
 * The whole lines the process holding the broken probe has printed: PROBE_HOLDER_STARTED, then the
 * probe's path relative to the root once it holds it.
 *
 * @return list<string>
 */
function probeHolderLines(Process $child): array
{
    $lines = explode("\n", $child->getOutput());
    array_pop($lines);

    return $lines;
}

/**
 * The path of the probe the process holds, relative to the root.
 */
function heldProbe(Process $child): string
{
    return probeHolderLines($child)[1] ?? '';
}

/**
 * The probe files below Node::PROBE_DIRECTORY, relative to the repository root.
 *
 * @return list<string>
 */
function probeFiles(): array
{
    return array_map(
        static fn (string $file): string => Node::PROBE_DIRECTORY.'/'.basename($file),
        glob(Phpstan::root().'/'.Node::PROBE_DIRECTORY.'/cms-probe-*') ?: [],
    );
}

/**
 * @param  Closure(): array{Process, list<string>}  $typecheck  the type check and the probes it could see
 */
function typecheckBesideBrokenProbe(Closure $typecheck): void
{
    $child = holdBrokenProbe();

    try {
        [$process, $probes] = $typecheck();
        $child->wait();
    } finally {
        $child->stop();
    }

    expect($child->getExitCode())->toBe(0, $child->getErrorOutput())
        ->and(str_contains($process->getOutput(), 'exactOptionalPropertyTypes'))->toBeFalse($process->getOutput())
        ->and($process->getExitCode())->toBe(0, $process->getOutput())
        ->and(heldProbe($child))->toStartWith(Node::PROBE_DIRECTORY.'/cms-probe-')
        ->and($probes)->not->toContain(heldProbe($child));
}

/**
 * @param  Closure(): array{Process, list<string>}  $typecheck
 */
it('type checks the whole project without the probe another process holds', function (Closure $typecheck): void {
    typecheckBesideBrokenProbe($typecheck);
})->with([
    'inside a probe of its own' => [static fn (): array => Node::withProbe(
        'ts',
        "export const checked: number = 1;\n",
        static fn (string $path): array => [Node::run(['npm', 'run', '--silent', 'typecheck']), [...probeFiles(), $path]],
    )],
    'without a probe' => [static fn (): array => [Node::run(['npm', 'run', '--silent', 'typecheck']), probeFiles()]],
]);

it('waits for the lock another process holds beyond the time the process holding the probe has to start', function (): void {
    // A run of gate 5 failed here when the queue for the exclusive lock was longer than the 30
    // seconds the process had to start (B1-review, 7 October 2026). This holds the lock for longer
    // than the start is given, as such a queue does.
    $startWithin = 3.0;
    $code = <<<'PHP'
        require $argv[1];

        Cbox\Cms\Tests\Support\JsToolchainLock::exclusive(static function () use ($argv): void {
            fwrite(STDOUT, "held\n");
            usleep((int) ($argv[2] * 1_000_000));
        });
        PHP;
    $blocker = new Process([PHP_BINARY, '-r', $code, Phpstan::root().'/vendor/autoload.php', (string) ($startWithin + 2)], Phpstan::root(), null, null, PROBE_HOLDER_TIMEOUT);
    $blocker->start();

    try {
        if (! $blocker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "held\n"))) {
            throw new RuntimeException("The process holding the lock stopped before it held it:\n".$blocker->getOutput().$blocker->getErrorOutput());
        }

        $held = microtime(true);
        $child = holdBrokenProbe($startWithin);
        $waited = microtime(true) - $held;

        try {
            $child->wait();
        } finally {
            $child->stop();
        }
    } finally {
        $blocker->stop();
    }

    expect($waited)->toBeGreaterThan($startWithin)
        ->and($child->getExitCode())->toBe(0, $child->getErrorOutput())
        ->and(heldProbe($child))->toStartWith(Node::PROBE_DIRECTORY.'/cms-probe-');
});

it('takes the lock once per process, so a tool run inside a probe does not wait for itself', function (): void {
    $order = JsToolchainLock::exclusive(static fn (): array => [
        'exclusive',
        ...JsToolchainLock::exclusive(static fn (): array => ['exclusive again', JsToolchainLock::shared(static fn (): string => 'shared')]),
    ]);

    expect($order)->toBe(['exclusive', 'exclusive again', 'shared']);
});

it('refuses to take the lock exclusively inside a shared hold', function (): void {
    JsToolchainLock::shared(static fn (): string => JsToolchainLock::exclusive(static fn (): string => 'exclusive'));
})->throws(LogicException::class, 'held shared');

it('scans the checkout for markers without the probe another process holds', function (): void {
    $child = holdBrokenProbe();

    try {
        $scan = MarkerScan::ofCheckout(Phpstan::root());
        $child->wait();
    } finally {
        $child->stop();
    }

    expect($child->getExitCode())->toBe(0, $child->getErrorOutput())
        ->and(heldProbe($child))->toStartWith(Node::PROBE_DIRECTORY.'/cms-probe-')
        ->and($scan->files)->toContain('composer.json')
        ->and($scan->files)->not->toContain(heldProbe($child));
});

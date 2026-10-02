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
 * Starts a PHP process that writes BROKEN_PROBE through Node::withProbe, prints its path and holds
 * it for three seconds, and for as long as another probe lies beside it (at most a minute), so a
 * tool run of this process that is not kept apart from it sees it for its whole run.
 */
function holdBrokenProbe(): Process
{
    $code = <<<'PHP'
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

    $child = new Process([PHP_BINARY, '-r', $code, Phpstan::root().'/vendor/autoload.php', BROKEN_PROBE], Phpstan::root(), null, null, 120);
    $child->start();

    $deadline = microtime(true) + 30;

    while ($child->getOutput() === '') {
        if (! $child->isRunning() || microtime(true) > $deadline) {
            $child->stop();

            throw new RuntimeException("The process holding the broken probe did not start:\n".$child->getOutput().$child->getErrorOutput());
        }

        usleep(10_000);
    }

    return $child;
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
        ->and($probes)->not->toContain(trim($child->getOutput()));
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
        ->and($scan->files)->toContain('composer.json')
        ->and($scan->files)->not->toContain(trim($child->getOutput()));
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\NestedTransactionGuard;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;

/*
 * The guard against nested transactions in the Postgres suite (PRD 4.2, GUARDRAILS 4.1).
 * Laravel issues SAVEPOINT when a connection's transactionLevel() goes above 1.
 */

/**
 * Runs $callback and returns the guard's failure.
 *
 * @param  Closure(): void  $callback
 */
function guardFailure(Closure $callback): AssertionFailedError
{
    try {
        $callback();
    } catch (AssertionFailedError $failure) {
        return $failure;
    }

    throw new AssertionFailedError('The guard did not fail the nested transaction.');
}

it('fails DB::transaction inside DB::transaction with a message that names SAVEPOINT', function (): void {
    $failure = guardFailure(static function (): void {
        DB::transaction(static function (): void {
            DB::transaction(static function (): void {});
        });
    });

    expect($failure->getMessage())
        ->toContain('Nested transaction on connection [pgsql]')
        ->toContain('transactionLevel() is 2')
        ->toContain('SAVEPOINT trans2')
        ->toContain('PRD 4.2')
        ->and(app(NestedTransactionGuard::class)->pullViolations())->toBe([$failure->getMessage()]);

    // Laravel rolled back to the savepoint but left the outer transaction open.
    expect(DB::transactionLevel())->toBe(1);
    DB::rollBack(0);
});

it('fails a second beginTransaction on the same connection', function (): void {
    DB::beginTransaction();
    $failure = guardFailure(static fn () => DB::beginTransaction());
    DB::rollBack(0);

    expect($failure->getMessage())->toContain('SAVEPOINT trans2')
        ->and(app(NestedTransactionGuard::class)->pullViolations())->toHaveCount(1);
});

it('guards independent connections, which share the application dispatcher', function (): void {
    [$connection] = app(IndependentConnections::class)->open(1);
    $connection->beginTransaction();
    $failure = guardFailure(static fn () => $connection->beginTransaction());

    expect($failure->getMessage())->toContain('Nested transaction on connection ['.$connection->getName().']')
        ->and(app(NestedTransactionGuard::class)->pullViolations())->toHaveCount(1);
});

it('allows one transaction at a time on each of several connections', function (): void {
    [$a, $b] = app(IndependentConnections::class)->open(2);

    $a->beginTransaction();
    $b->beginTransaction();
    DB::transaction(static function (): void {});
    DB::transaction(static function (): void {});
    $a->commit();
    $b->commit();

    expect(app(NestedTransactionGuard::class)->pullViolations())->toBe([]);
});

it('makes a Pest test fail through the harness, even when the test catches the failure', function (): void {
    $root = dirname(ChildProcesses::autoloader(), 2);
    $fixture = __DIR__.'/fixtures/nested-transactions.php';

    $run = new Process([PHP_BINARY, $root.'/vendor/bin/pest', $fixture, '--colors=never'], $root);
    $run->setTimeout(120);
    $run->run();
    $output = $run->getOutput().$run->getErrorOutput();

    expect($run->getExitCode())->not->toBe(0)
        ->and($output)
        ->toContain('3 failed, 1 passed')
        ->toContain('SAVEPOINT trans2')
        ->toContain('nests DB::transaction inside DB::transaction')
        ->toContain('swallows the failure of a nested begin')
        ->toContain('nests on an independent connection');
});

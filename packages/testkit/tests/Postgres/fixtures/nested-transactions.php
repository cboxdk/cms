<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres\Fixtures;

use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
 * Fixture for NestedTransactionGuardTest, run in a separate Pest process. It is not a suite
 * file (no Test.php suffix); tests/Pest.php gives it the TestCase and RealPostgres because it
 * sits below packages/testkit/tests/Postgres. Three tests must fail because of the guard and
 * one must pass.
 */

it('fixture: nests DB::transaction inside DB::transaction', function (): void {
    DB::transaction(static function (): void {
        DB::transaction(static function (): void {});
    });
});

it('fixture: swallows the failure of a nested begin', function (): void {
    DB::beginTransaction();

    try {
        DB::beginTransaction();
    } catch (Throwable) {
        // Hiding the guard's failure does not help: tear-down fails the test.
    }

    DB::rollBack(0);

    expect(true)->toBeTrue();
});

it('fixture: nests on an independent connection', function (): void {
    [$connection] = app(IndependentConnections::class)->open(1);

    $connection->transaction(static function () use ($connection): void {
        $connection->transaction(static function (): void {});
    });
});

it('fixture: uses one transaction per connection', function (): void {
    [$a, $b] = app(IndependentConnections::class)->open(2);

    $a->beginTransaction();
    $b->beginTransaction();
    DB::transaction(static function (): void {});
    $a->commit();
    $b->commit();

    expect(DB::transactionLevel())->toBe(0);
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 * Independent connections (GUARDRAILS 9: real Postgres with separate connections and real
 * commits). Each is its own backend, so Postgres isolation between them is real.
 */

it('opens N connections, each its own backend as the app role', function (): void {
    $connections = app(IndependentConnections::class)->open(3);

    $backends = array_map(
        static fn (PostgresConnection $connection): string => json_encode($connection->scalar('select pg_backend_pid()'), JSON_THROW_ON_ERROR),
        $connections,
    );
    $roles = array_map(
        static fn (PostgresConnection $connection): string => json_encode($connection->scalar('select current_user'), JSON_THROW_ON_ERROR),
        $connections,
    );

    expect($connections)->toHaveCount(3)
        ->and(array_unique([...$backends, json_encode(DB::scalar('select pg_backend_pid()'), JSON_THROW_ON_ERROR)]))->toHaveCount(4)
        ->and(array_unique($roles))->toBe(['"cms_app"']);
});

it('keeps a row that connection A wrote but did not commit invisible to connection B, until A commits', function (): void {
    $table = Probe::table();
    [$a, $b] = app(IndependentConnections::class)->open(2);

    $a->beginTransaction();
    $id = $a->table($table)->insertGetId(['note' => 'written on A']);

    expect($a->table($table)->where('id', $id)->count())->toBe(1)
        ->and($b->table($table)->where('id', $id)->count())->toBe(0)
        ->and(DB::table($table)->where('id', $id)->count())->toBe(0);

    $a->commit();

    expect($b->table($table)->where('id', $id)->count())->toBe(1)
        ->and(DB::table($table)->where('id', $id)->count())->toBe(1);
});

it('copies another configured connection, such as the owner', function (): void {
    [$owner] = app(IndependentConnections::class)->open(1, 'pgsql_owner');

    expect($owner->scalar('select current_user'))->toBe('cms_owner')
        ->and($owner->getName())->toStartWith('pgsql_owner__independent_');
});

it('closes the connections after the test and rolls back what they left open', function (): void {
    $table = Probe::table();
    $connections = app(IndependentConnections::class);
    [$left] = $connections->open(1);
    $left->beginTransaction();
    $left->table($table)->insert(['note' => 'never committed']);
    $name = (string) $left->getName();

    $connections->closeAll();

    expect($name)->toStartWith('pgsql__independent_')
        ->and(DB::table($table)->count())->toBe(0)
        ->and(DB::getConnections())->not->toHaveKey($name)
        ->and(config('database.connections.'.$name))->toBeNull();
});

it('refuses to open fewer than one connection', function (): void {
    app(IndependentConnections::class)->open(0);
})->throws(InvalidArgumentException::class, 'Open at least one connection.');

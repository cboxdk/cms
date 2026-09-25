<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
 * The role part of the Postgres operating contract (PRD 4.2, GUARDRAILS 4.1 and 6),
 * checked against the Postgres 17 service in compose.yaml. The default connection is the
 * app role; pgsql_owner is the owner role that runs migrations.
 */

/**
 * Asserts that the statement fails with insufficient_privilege (SQLSTATE 42501).
 */
function expectInsufficientPrivilege(string $sql, ?string $connection = null): void
{
    try {
        DB::connection($connection)->statement($sql);
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501');

        return;
    }

    Assert::fail("Expected [{$sql}] to fail with SQLSTATE 42501, but it succeeded.");
}

/**
 * Runs the callback with a fresh table created by the owner role, and drops the table after.
 *
 * @param  Closure(string): void  $callback  receives the table name
 */
function withOwnerTable(Closure $callback): void
{
    $table = 'contract_probe_'.Str::lower(Str::random(8));
    $owner = DB::connection('pgsql_owner');
    $owner->statement("create table {$table} (id bigserial primary key, x int not null)");

    try {
        $callback($table);
    } finally {
        $owner->statement("drop table if exists {$table}");
        $owner->disconnect();
    }
}

it('connects to Postgres 17 as the app role in the cms schema', function (): void {
    $row = DB::selectOne(
        "select current_user as role, current_setting('server_version_num')::int / 10000 as major, current_schema() as schema"
    );

    // Pinned to 17, not "at least 17": the tests must not reach Herd's Postgres 18 by mistake.
    expect($row)->toEqual((object) ['role' => 'cms_app', 'major' => 17, 'schema' => 'cms']);
});

it('gives the app role no superuser, no BYPASSRLS and no role or database creation', function (): void {
    $row = DB::selectOne(
        'select rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolreplication from pg_roles where rolname = current_user'
    );

    expect($row)->toEqual((object) [
        'rolsuper' => false,
        'rolbypassrls' => false,
        'rolcreatedb' => false,
        'rolcreaterole' => false,
        'rolreplication' => false,
    ]);
});

it('caps every transaction of the app role at the 5 second command budget', function (): void {
    expect(DB::selectOne('show transaction_timeout'))->toEqual((object) ['transaction_timeout' => '5s']);
});

it('runs with max_prepared_transactions set to 0', function (): void {
    expect(DB::selectOne('show max_prepared_transactions'))->toEqual((object) ['max_prepared_transactions' => '0']);
});

it('denies the app role DDL: tables, schemas and temporary tables', function (string $sql): void {
    expectInsufficientPrivilege($sql);
})->with([
    'table in the cms schema' => 'create table contract_probe (x int)',
    'table in public' => 'create table public.contract_probe (x int)',
    'schema' => 'create schema contract_probe',
    'temporary table' => 'create temporary table contract_probe (x int)',
]);

it('lets the app role own nothing in the database', function (): void {
    $owned = DB::selectOne(
        'select count(*) as objects from pg_class where relowner = (select oid from pg_roles where rolname = current_user)'
    );

    expect($owned)->toEqual((object) ['objects' => 0]);
});

it('gives the owner role no transaction_timeout, because index DDL runs outside a transaction', function (): void {
    $owner = DB::connection('pgsql_owner');

    expect($owner->selectOne('select current_user as role, rolsuper from pg_roles where rolname = current_user'))
        ->toEqual((object) ['role' => 'cms_owner', 'rolsuper' => false])
        ->and($owner->selectOne('show transaction_timeout'))
        ->toEqual((object) ['transaction_timeout' => '0']);
});

describe('a table the owner role creates', function (): void {
    it('is writable by the app role through the default privileges', function (): void {
        withOwnerTable(function (string $table): void {
            $id = DB::table($table)->insertGetId(['x' => 1]);

            expect(DB::table($table)->where('id', $id)->update(['x' => 2]))->toBe(1)
                ->and(DB::table($table)->where('id', $id)->value('x'))->toBe(2)
                ->and(DB::table($table)->where('id', $id)->delete())->toBe(1);
        });
    });

    it('cannot be altered, truncated or dropped by the app role', function (string $sql): void {
        withOwnerTable(function (string $table) use ($sql): void {
            expectInsufficientPrivilege(sprintf($sql, $table));
        });
    })->with([
        'alter' => 'alter table %s add column y int',
        'index' => 'create index on %s (x)',
        'truncate' => 'truncate %s',
        'drop' => 'drop table %s',
    ]);
});

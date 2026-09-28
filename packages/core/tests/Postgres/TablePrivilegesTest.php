<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\ColumnGrant;
use Cbox\Cms\Core\Database\Infrastructure\TableGrant;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

/*
 * TablePrivileges on the Postgres 18 service of compose.yaml (PRD 4.2, GUARDRAILS 6): the table
 * privileges are the closed set TablePrivilege (GUARDRAILS 2.2), which limitTo() takes and grants()
 * reads back, on a scratch table the owner role creates with the owner's default privileges.
 * limitTo() only narrows: a role keeps the privileges it holds that are in the list and never
 * gains one, so a role the installation gave less than the list, or PUBLIC, is not widened. Roles
 * belong to the cluster, so a test that needs one makes it under a random name as the superuser of
 * compose.yaml and drops it afterwards.
 */

const TABLE_PRIVILEGES_SCRATCH = 'table_privileges_scratch';

const TABLE_PRIVILEGES_SUPERUSER = 'pgsql_table_privileges_superuser';

function tablePrivilegesOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function tablePrivilegesSuperuser(): Connection
{
    $env = static function (string $key): string {
        $value = Env::get($key);

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("{$key} is not set; phpunit.xml names the superuser of compose.yaml.");
        }

        return $value;
    };

    config(['database.connections.'.TABLE_PRIVILEGES_SUPERUSER => array_merge((array) config('database.connections.pgsql'), [
        'username' => $env('DB_SUPERUSER_USERNAME'),
        'password' => $env('DB_SUPERUSER_PASSWORD'),
    ])]);

    return DB::connection(TABLE_PRIVILEGES_SUPERUSER);
}

/**
 * Makes a role that cannot log in and returns its name, which quote_ident() leaves as it is.
 */
function tablePrivilegesRole(): string
{
    $role = 'cms_table_privileges_'.bin2hex(random_bytes(6));
    tablePrivilegesSuperuser()->statement(sprintf('create role %s nologin', $role));
    TablePrivilegesScratchRoles::$roles[] = $role;

    return $role;
}

/**
 * @return list<string>
 */
function tablePrivilegesGrants(string $table): array
{
    return array_map(
        static fn (TableGrant $grant): string => $grant->role.' '.$grant->privilege->value,
        new TablePrivileges(tablePrivilegesOwner())->grants($table),
    );
}

beforeEach(function (): void {
    tablePrivilegesOwner()->statement(sprintf('drop table if exists %s cascade', TABLE_PRIVILEGES_SCRATCH));
    tablePrivilegesOwner()->statement(sprintf('create table %s (id bigint primary key) partition by range (id)', TABLE_PRIVILEGES_SCRATCH));
    tablePrivilegesOwner()->statement(sprintf('create table %1$s_p0 partition of %1$s for values from (0) to (100)', TABLE_PRIVILEGES_SCRATCH));
});

afterEach(function (): void {
    tablePrivilegesOwner()->statement(sprintf('drop table if exists %s cascade', TABLE_PRIVILEGES_SCRATCH));

    if (TablePrivilegesScratchRoles::$roles === []) {
        return;
    }

    foreach (TablePrivilegesScratchRoles::$roles as $role) {
        tablePrivilegesSuperuser()->statement(sprintf('drop owned by %s', $role));
        tablePrivilegesSuperuser()->statement(sprintf('drop role if exists %s', $role));
    }

    TablePrivilegesScratchRoles::$roles = [];
    DB::purge(TABLE_PRIVILEGES_SUPERUSER);
});

it('has the table privileges of Postgres 17 as GRANT writes them', function (): void {
    expect(array_map(static fn (TablePrivilege $privilege): string => $privilege->value, TablePrivilege::cases()))
        ->toBe(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN']);

    foreach (TablePrivilege::cases() as $privilege) {
        expect(tablePrivilegesOwner()->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [TABLE_PRIVILEGES_SCRATCH, $privilege->value]))
            ->toBeTrue($privilege->value);
    }
});

it('reads the grants as TablePrivilege cases', function (): void {
    $grants = new TablePrivileges(tablePrivilegesOwner())->grants(TABLE_PRIVILEGES_SCRATCH);

    expect(array_map(static fn (TableGrant $grant): TablePrivilege => $grant->privilege, $grants))
        ->toBe([TablePrivilege::Delete, TablePrivilege::Insert, TablePrivilege::Select, TablePrivilege::Update]);
});

it('limits the table and its partitions to the privileges given, once each', function (): void {
    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Select]);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app INSERT', 'cms_app SELECT'])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app INSERT', 'cms_app SELECT']);

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Insert, TablePrivilege::Maintain]);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app INSERT'])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app INSERT']);

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, []);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe([])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe([]);
});

it('never widens a role that holds less than the list, nor PUBLIC', function (): void {
    $reporting = tablePrivilegesRole();

    foreach ([TABLE_PRIVILEGES_SCRATCH, TABLE_PRIVILEGES_SCRATCH.'_p0'] as $relation) {
        tablePrivilegesOwner()->statement(sprintf('grant select on table %s to %s', $relation, $reporting));
        tablePrivilegesOwner()->statement(sprintf('grant select on table %s to public', $relation));
    }

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Select, TablePrivilege::Insert]);

    $expected = ['cms_app INSERT', 'cms_app SELECT', $reporting.' SELECT', 'public SELECT'];

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe($expected)
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe($expected)
        ->and(tablePrivilegesOwner()->scalar('select has_table_privilege(?, ?::regclass, ?)', [$reporting, TABLE_PRIVILEGES_SCRATCH, 'INSERT']))->toBeFalse();
});

it('keeps the grant option of a privilege it keeps and revokes the privileges not in the list', function (): void {
    $reporting = tablePrivilegesRole();
    tablePrivilegesOwner()->statement(sprintf('grant select, update on table %s to %s with grant option', TABLE_PRIVILEGES_SCRATCH, $reporting));

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Select, TablePrivilege::Insert]);

    $grants = array_map(
        static fn (TableGrant $grant): string => sprintf('%s %s%s', $grant->role, $grant->privilege->value, $grant->grantable ? ' grantable' : ''),
        new TablePrivileges(tablePrivilegesOwner())->grants(TABLE_PRIVILEGES_SCRATCH),
    );

    expect($grants)->toBe(['cms_app INSERT', 'cms_app SELECT', $reporting.' SELECT grantable'])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app INSERT', 'cms_app SELECT', $reporting.' SELECT']);
});

it('gives the partitions the narrowed grants of the table and nothing the table does not grant', function (): void {
    $stray = tablePrivilegesRole();
    tablePrivilegesOwner()->statement(sprintf('revoke insert on table %s_p0 from cms_app', TABLE_PRIVILEGES_SCRATCH));
    tablePrivilegesOwner()->statement(sprintf('grant select on table %s_p0 to %s', TABLE_PRIVILEGES_SCRATCH, $stray));

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Select, TablePrivilege::Insert]);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app INSERT', 'cms_app SELECT'])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app INSERT', 'cms_app SELECT']);
});

/**
 * @return list<string>
 */
function tablePrivilegesColumnGrants(string $table): array
{
    return array_map(
        static fn (ColumnGrant $grant): string => sprintf('%s %s %s%s', $grant->role, $grant->privilege->value, $grant->column, $grant->grantable ? ' grantable' : ''),
        new TablePrivileges(tablePrivilegesOwner())->columnGrants($table),
    );
}

function tablePrivilegesAddColumns(): void
{
    tablePrivilegesOwner()->statement(sprintf('alter table %s add column state text, add column note text, add column "Mixed Case" text', TABLE_PRIVILEGES_SCRATCH));
}

it('narrows a privilege to the columns given, on the table and its partitions, and keeps the rest of the table grants', function (): void {
    tablePrivilegesAddColumns();

    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, ['Mixed Case', 'state', 'state']);

    foreach ([TABLE_PRIVILEGES_SCRATCH, TABLE_PRIVILEGES_SCRATCH.'_p0'] as $relation) {
        expect(tablePrivilegesGrants($relation))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT'], $relation)
            ->and(tablePrivilegesColumnGrants($relation))->toBe(['cms_app UPDATE "Mixed Case"', 'cms_app UPDATE state'], $relation);

        foreach (['id' => false, 'state' => true, 'note' => false, 'Mixed Case' => true] as $column => $updatable) {
            expect(tablePrivilegesOwner()->scalar("select has_column_privilege('cms_app', ?::regclass, ?, 'UPDATE')", [$relation, $column]))
                ->toBe($updatable, "UPDATE of {$column} on {$relation}");
        }
    }

    // A second narrowing of a role that no longer holds the privilege on the whole table changes nothing.
    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, ['note']);

    expect(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app UPDATE "Mixed Case"', 'cms_app UPDATE state'])
        ->and(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app UPDATE "Mixed Case"', 'cms_app UPDATE state']);
});

it('refuses the app role an update of a column it was not given, through the table and through a partition', function (): void {
    tablePrivilegesAddColumns();
    tablePrivilegesOwner()->insert(sprintf('insert into %s (id, state, note) values (1, ?, ?)', TABLE_PRIVILEGES_SCRATCH), ['pending', 'kept']);

    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, ['state']);

    foreach ([TABLE_PRIVILEGES_SCRATCH, TABLE_PRIVILEGES_SCRATCH.'_p0'] as $relation) {
        expect(DB::connection()->update(sprintf('update %s set state = ? where id = 1', $relation), ['done']))->toBe(1);

        foreach (['note = \'changed\'', 'id = 2', 'state = \'x\', note = \'changed\''] as $assignment) {
            expect(fn () => DB::connection()->update(sprintf('update %s set %s where id = 1', $relation, $assignment)))
                ->toThrow(QueryException::class, 'permission denied');
        }
    }

    expect(tablePrivilegesOwner()->scalar(sprintf('select id || \' \' || state || \' \' || note from %s', TABLE_PRIVILEGES_SCRATCH)))->toBe('1 done kept');
});

it('keeps the grant option when it narrows to columns, and never widens a role that holds the privilege on no column or some columns, nor PUBLIC', function (): void {
    tablePrivilegesAddColumns();
    $delegating = tablePrivilegesRole();
    $partial = tablePrivilegesRole();
    $none = tablePrivilegesRole();
    tablePrivilegesOwner()->statement(sprintf('grant update on table %s to %s with grant option', TABLE_PRIVILEGES_SCRATCH, $delegating));
    tablePrivilegesOwner()->statement(sprintf('grant update (note) on table %s to %s', TABLE_PRIVILEGES_SCRATCH, $partial));
    tablePrivilegesOwner()->statement(sprintf('grant select on table %s to %s', TABLE_PRIVILEGES_SCRATCH, $none));
    tablePrivilegesOwner()->statement(sprintf('grant update on table %s to public', TABLE_PRIVILEGES_SCRATCH));

    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, ['state']);

    $expected = ['cms_app UPDATE state', $delegating.' UPDATE state grantable', $partial.' UPDATE note', 'public UPDATE state'];
    sort($expected);

    expect(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH))->toBe($expected)
        ->and(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe($expected)
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT', $none.' SELECT'])
        // PUBLIC keeps UPDATE of state for every role, and no role gains another column.
        ->and(tablePrivilegesOwner()->scalar("select has_column_privilege(?, ?::regclass, 'note', 'UPDATE')", [$none, TABLE_PRIVILEGES_SCRATCH]))->toBeFalse()
        ->and(tablePrivilegesOwner()->scalar("select has_column_privilege(?, ?::regclass, 'id', 'UPDATE')", [$partial, TABLE_PRIVILEGES_SCRATCH]))->toBeFalse()
        ->and(tablePrivilegesOwner()->scalar("select has_column_privilege(?, ?::regclass, 'note', 'UPDATE')", [$delegating, TABLE_PRIVILEGES_SCRATCH]))->toBeFalse();
});

it('takes the privilege away when no column is given', function (): void {
    tablePrivilegesAddColumns();

    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, []);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT'])
        ->and(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH))->toBe([])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT'])
        ->and(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe([]);
});

it('refuses a privilege no column has and a column the table does not have, before it changes anything', function (TablePrivilege $privilege, string $column, string $message): void {
    tablePrivilegesAddColumns();

    expect(fn () => new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, $privilege, [$column]))
        ->toThrow(LogicException::class, $message)
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT', 'cms_app UPDATE'])
        ->and(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH))->toBe([]);
})->with([
    'DELETE' => [TablePrivilege::Delete, 'state', 'Postgres grants DELETE only on a whole table, not on its columns.'],
    'TRUNCATE' => [TablePrivilege::Truncate, 'state', 'Postgres grants TRUNCATE only on a whole table, not on its columns.'],
    'TRIGGER' => [TablePrivilege::Trigger, 'state', 'Postgres grants TRIGGER only on a whole table, not on its columns.'],
    'MAINTAIN' => [TablePrivilege::Maintain, 'state', 'Postgres grants MAINTAIN only on a whole table, not on its columns.'],
    'an unknown column' => [TablePrivilege::Update, 'missing', 'The table ['.TABLE_PRIVILEGES_SCRATCH.'] has no column [missing].'],
    'a column in another case' => [TablePrivilege::Update, 'mixed case', 'The table ['.TABLE_PRIVILEGES_SCRATCH.'] has no column [mixed case].'],
]);

it('copies the column grants of the table to a partition and takes away the column grants the table does not have', function (): void {
    tablePrivilegesAddColumns();
    $stray = tablePrivilegesRole();
    $delegating = tablePrivilegesRole();
    tablePrivilegesOwner()->statement(sprintf('grant update (note) on table %s to %s with grant option', TABLE_PRIVILEGES_SCRATCH, $delegating));
    tablePrivilegesOwner()->statement(sprintf('grant select (id) on table %s to %s', TABLE_PRIVILEGES_SCRATCH, $delegating));
    new TablePrivileges(tablePrivilegesOwner())->limitColumns(TABLE_PRIVILEGES_SCRATCH, TablePrivilege::Update, ['state']);

    // A partition the table knows nothing of has other column grants, and a wider grant option.
    tablePrivilegesOwner()->statement(sprintf('create table %1$s_p1 (like %1$s)', TABLE_PRIVILEGES_SCRATCH));
    tablePrivilegesOwner()->statement(sprintf('grant update (note, state) on table %s_p1 to %s', TABLE_PRIVILEGES_SCRATCH, $stray));
    tablePrivilegesOwner()->statement(sprintf('grant update (state) on table %s_p1 to %s with grant option', TABLE_PRIVILEGES_SCRATCH, $delegating));
    tablePrivilegesOwner()->statement(sprintf('grant select (id) on table %s_p1 to %s with grant option', TABLE_PRIVILEGES_SCRATCH, $delegating));

    new TablePrivileges(tablePrivilegesOwner())->copy(TABLE_PRIVILEGES_SCRATCH, TABLE_PRIVILEGES_SCRATCH.'_p1');

    $expected = ['cms_app UPDATE state', $delegating.' SELECT id', $delegating.' UPDATE note grantable'];
    sort($expected);

    expect(tablePrivilegesColumnGrants(TABLE_PRIVILEGES_SCRATCH.'_p1'))->toBe($expected)
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p1'))->toBe(['cms_app DELETE', 'cms_app INSERT', 'cms_app SELECT']);

    // A copy onto a table that already has the grants writes nothing.
    $statements = 0;
    tablePrivilegesOwner()->listen(static function (QueryExecuted $query) use (&$statements): void {
        if (preg_match('/^(grant|revoke) /', $query->sql) === 1) {
            $statements++;
        }
    });
    new TablePrivileges(tablePrivilegesOwner())->copy(TABLE_PRIVILEGES_SCRATCH, TABLE_PRIVILEGES_SCRATCH.'_p1');

    expect($statements)->toBe(0);
});

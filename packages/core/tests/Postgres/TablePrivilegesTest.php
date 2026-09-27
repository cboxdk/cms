<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TableGrant;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Connection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
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

/**
 * The roles one test made, which afterEach drops.
 */
final class TablePrivilegesScratchRoles
{
    /** @var list<string> */
    public static array $roles = [];
}

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

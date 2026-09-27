<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TableGrant;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
 * TablePrivileges on the Postgres 18 service of compose.yaml (PRD 4.2, GUARDRAILS 6): the table
 * privileges are the closed set TablePrivilege (GUARDRAILS 2.2), which limitTo() takes and grants()
 * reads back, on a scratch table the owner role creates with the owner's default privileges.
 */

const TABLE_PRIVILEGES_SCRATCH = 'table_privileges_scratch';

function tablePrivilegesOwner(): Connection
{
    return DB::connection('pgsql_owner');
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

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, [TablePrivilege::Maintain]);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe(['cms_app MAINTAIN'])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe(['cms_app MAINTAIN']);

    new TablePrivileges(tablePrivilegesOwner())->limitTo(TABLE_PRIVILEGES_SCRATCH, []);

    expect(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH))->toBe([])
        ->and(tablePrivilegesGrants(TABLE_PRIVILEGES_SCRATCH.'_p0'))->toBe([]);
});

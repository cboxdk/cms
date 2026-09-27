<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use UnexpectedValueException;

/*
 * postgres.app_role and postgres.ddl_privileges on the Postgres 18 service of compose.yaml for a
 * role that reaches more power through role membership than through its own attributes (PRD 4.2):
 * a scratch login role with the app role's attributes, made by the superuser of compose.yaml, is
 * granted the owner role, a BYPASSRLS, CREATEROLE or superuser role, a role that owns or may
 * create objects in the database or a schema, or a predefined role that reaches every table or
 * the server, signals other sessions or reads their query text; or it has CREATEROLE itself. Roles belong to the cluster, not to the checkout's
 * database, so each test makes roles of its own under random names and drops them afterwards.
 */

afterEach(function (): void {
    ScratchRoles::drop();
});

/**
 * The owner role of the checkout's database.
 */
function doctorOwnerRole(): string
{
    $owner = config('database.connections.pgsql_owner.username');

    if (! is_string($owner)) {
        throw new UnexpectedValueException('The connection pgsql_owner has no username.');
    }

    return $owner;
}

/**
 * @return array{CheckResult, CheckResult}
 */
function doctorRoleChecks(): array
{
    $postgres = app(PostgresProbe::class);

    return [new AppRoleCheck($postgres)->run(), new DdlPrivilegesCheck($postgres)->run()];
}

it('passes a scratch role with the app role\'s attributes and no memberships', function (): void {
    $role = ScratchRoles::login();

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Pass, (string) $appRole->cause)
        ->and($appRole->explanation)->toContain($role)
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
});

it('fails both checks for an app role granted the owner role, with or without INHERIT', function (string $grant): void {
    $role = ScratchRoles::login();
    $owner = doctorOwnerRole();
    ScratchRoles::superuser()->statement(sprintf('grant "%s" to "%s" %s', $owner, $role, $grant));

    $postgres = app(PostgresProbe::class);
    $privileges = $postgres->ddlPrivileges();

    // The owner role owns the checkout's database, so its members also reach pg_database_owner,
    // which owns the schema public, and roles.sql makes it a member of pg_signal_backend.
    expect($postgres->role()->memberships)->toEqual([
        new RoleMembership($owner, superuser: false, bypassRowSecurity: false, ownsRelations: true, createRole: false, createsObjects: true),
        new RoleMembership('pg_database_owner', superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: true),
        new RoleMembership('pg_signal_backend', superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: false),
    ])
        ->and($privileges->ownerRoles)->toBe([$owner])
        ->and($privileges->ownedCount)->toBeGreaterThan(6)
        ->and($privileges->ownedRelations)->toHaveCount(5)
        ->and($privileges->ownedRelations)->toBe(array_values(array_unique($privileges->ownedRelations)));

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which owns relations and owns or may create objects in the database or its schemas; pg_database_owner, which owns or may create objects in the database or its schemas; pg_signal_backend, which cancels and terminates the sessions of every other non-superuser role, the owner\'s migrations and partition maintenance included.', $role, $owner))
        ->and($appRole->fix)->toStartWith(sprintf('Run REVOKE %s, pg_signal_backend FROM %s as a superuser', $owner, $role))
        ->and($appRole->fix)->toContain('pg_database_owner comes from owning the database')
        ->and($ddl->status)->toBe(CheckStatus::Fail)
        ->and($ddl->failure)->toBe(FailureKind::Violation)
        ->and($ddl->code)->toBe(DdlPrivilegesCheck::CODE)
        ->and($ddl->cause)->toContain(sprintf('The role %s: it owns ', $role))
        ->and($ddl->cause)->toContain(sprintf('relations through its membership of %s, such as cms.', $owner))
        ->and($ddl->fix)->toContain(sprintf('REVOKE %s FROM %s', $owner, $role));
})->with([
    'with SET only' => ['with inherit false, set true'],
    'with INHERIT only' => ['with inherit true, set false'],
    'with the defaults' => [''],
]);

it('fails postgres.app_role for an app role that is a member of a BYPASSRLS or superuser role', function (string $attributes, string $has): void {
    $role = ScratchRoles::login();
    $middle = ScratchRoles::group('noinherit');
    $powerful = ScratchRoles::group($attributes);
    $superuser = ScratchRoles::superuser();
    $superuser->statement(sprintf('grant "%s" to "%s"', $powerful, $middle));
    $superuser->statement(sprintf('grant "%s" to "%s"', $middle, $role));

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which has %s.', $role, $powerful, $has))
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
})->with([
    'BYPASSRLS' => ['bypassrls', 'BYPASSRLS'],
    'SUPERUSER' => ['superuser', 'SUPERUSER'],
]);

it('passes an app role that is a member of a role with no more power than its own', function (): void {
    $role = ScratchRoles::login();
    $group = ScratchRoles::group('nosuperuser nobypassrls nocreaterole');
    ScratchRoles::superuser()->statement(sprintf('grant "%s" to "%s"', $group, $role));

    [$appRole, $ddl] = doctorRoleChecks();

    expect(app(PostgresProbe::class)->role()->memberships)->toBe([])
        ->and($appRole->status)->toBe(CheckStatus::Pass, (string) $appRole->cause)
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
});

it('fails postgres.app_role for an app role that is a member of a predefined role that reaches every table or the server, signals other sessions or reads their query text', function (string $predefined, string $gives): void {
    $role = ScratchRoles::login();
    $middle = ScratchRoles::group('noinherit');
    $superuser = ScratchRoles::superuser();
    $superuser->statement(sprintf('grant "%s" to "%s"', $predefined, $middle));
    $superuser->statement(sprintf('grant "%s" to "%s" with inherit false, set true', $middle, $role));

    expect(app(PostgresProbe::class)->role()->memberships)
        ->toEqual([new RoleMembership($predefined, superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: false)]);

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which %s.', $role, $predefined, $gives))
        ->and($appRole->fix)->toContain(sprintf('REVOKE %s FROM %s', $predefined, $role))
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
})->with([
    'pg_write_all_data' => ['pg_write_all_data', 'writes every table, append-only ones included'],
    'pg_read_all_data' => ['pg_read_all_data', 'reads every table'],
    'pg_maintain' => ['pg_maintain', 'maintains, reindexes and locks every table like its owner'],
    'pg_execute_server_program' => ['pg_execute_server_program', 'runs programs on the database server'],
    'pg_read_server_files' => ['pg_read_server_files', 'reads files on the database server'],
    'pg_write_server_files' => ['pg_write_server_files', 'writes files on the database server'],
    'pg_signal_backend' => ['pg_signal_backend', 'cancels and terminates the sessions of every other non-superuser role, the owner\'s migrations and partition maintenance included'],
    'pg_read_all_stats' => ['pg_read_all_stats', 'reads the query text of every session'],
]);

it('fails postgres.app_role for an app role granted pg_signal_backend as roles.sql grants it to the owner role', function (): void {
    $role = ScratchRoles::login();
    ScratchRoles::superuser()->statement(sprintf('grant pg_signal_backend to "%s"', $role));

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of pg_signal_backend, which cancels and terminates the sessions of every other non-superuser role, the owner\'s migrations and partition maintenance included.', $role))
        ->and($appRole->fix)->toStartWith(sprintf('Run REVOKE pg_signal_backend FROM %s as a superuser', $role))
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
});

it('fails postgres.app_role for an app role granted pg_monitor, naming pg_monitor and the pg_read_all_stats it includes', function (): void {
    $role = ScratchRoles::login();
    ScratchRoles::superuser()->statement(sprintf('grant pg_monitor to "%s"', $role));

    expect(app(PostgresProbe::class)->role()->memberships)->toEqual([
        new RoleMembership('pg_monitor', superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: false),
        new RoleMembership('pg_read_all_stats', superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: false),
    ]);

    [$appRole] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of pg_monitor, which reads the query text of every session and every server setting; pg_read_all_stats, which reads the query text of every session.', $role))
        ->and($appRole->fix)->toStartWith(sprintf('Run REVOKE pg_monitor, pg_read_all_stats FROM %s as a superuser', $role));
});

it('fails postgres.app_role for an app role that can SET ROLE without INHERIT to a role that owns or may create objects in the database or a schema', function (string $grant): void {
    $role = ScratchRoles::login();
    $group = ScratchRoles::group('');
    $superuser = ScratchRoles::superuser();
    $superuser->statement(sprintf($grant, $group, $superuser->getDatabaseName()));
    $superuser->statement(sprintf('grant "%s" to "%s" with inherit false, set true', $group, $role));

    expect(app(PostgresProbe::class)->role()->memberships)
        ->toEqual([new RoleMembership($group, superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: true)]);

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which owns or may create objects in the database or its schemas.', $role, $group))
        ->and($appRole->fix)->toContain(sprintf('REVOKE %s FROM %s', $group, $role))
        ->and($ddl->status)->toBe(CheckStatus::Pass, (string) $ddl->cause);
})->with([
    'CREATE on a schema' => ['grant create on schema public to "%1$s"'],
    'CREATE on the database' => ['grant create on database "%2$s" to "%1$s"'],
    'a schema it owns' => ['create schema "%1$s" authorization "%1$s"'],
]);

it('fails postgres.app_role for an app role with CREATEROLE', function (): void {
    $role = ScratchRoles::login();
    ScratchRoles::superuser()->statement(sprintf('alter role "%s" createrole', $role));

    expect(app(PostgresProbe::class)->role()->createRole)->toBeTrue();

    [$appRole] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_CREATEROLE)
        ->and($appRole->cause)->toBe(sprintf('The role %s has CREATEROLE.', $role))
        ->and($appRole->fix)->toBe(sprintf('Run ALTER ROLE %s NOCREATEROLE as a superuser.', $role));
});

it('fails postgres.app_role for an app role that can SET ROLE to a CREATEROLE role', function (): void {
    $role = ScratchRoles::login();
    $group = ScratchRoles::group('createrole');
    ScratchRoles::superuser()->statement(sprintf('grant "%s" to "%s" with inherit false, set true', $group, $role));

    [$appRole] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which has CREATEROLE.', $role, $group));
});

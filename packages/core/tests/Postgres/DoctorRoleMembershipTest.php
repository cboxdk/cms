<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Illuminate\Database\Connection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/*
 * postgres.app_role and postgres.ddl_privileges on the Postgres 18 service of compose.yaml for a
 * role that reaches more power through role membership than through its own attributes (PRD 4.2):
 * a scratch login role with the app role's attributes, made by the superuser of compose.yaml, is
 * granted the owner role, a BYPASSRLS, CREATEROLE or superuser role, a role that owns or may
 * create objects in the database or a schema, or a predefined role that reaches every table or
 * the server; or it has CREATEROLE itself. Roles belong to the cluster, not to the checkout's
 * database, so each test makes roles of its own under random names and drops them afterwards.
 */

/**
 * The scratch roles of one test and the superuser's connection that makes and drops them.
 */
final class ScratchRoles
{
    public const string CONNECTION = 'pgsql_doctor_superuser';

    public const string LOGIN_CONNECTION = 'pgsql_doctor_scratch';

    /** @var list<string> */
    public static array $roles = [];

    public static function superuser(): Connection
    {
        config(['database.connections.'.self::CONNECTION => array_merge((array) config('database.connections.pgsql'), [
            'username' => self::env('DB_SUPERUSER_USERNAME'),
            'password' => self::env('DB_SUPERUSER_PASSWORD'),
        ])]);

        return DB::connection(self::CONNECTION);
    }

    /**
     * Makes a role that cannot log in, with the attributes given, and returns its name.
     */
    public static function group(string $attributes): string
    {
        $role = self::name();
        self::superuser()->statement(sprintf('create role "%s" nologin %s', $role, $attributes));

        return $role;
    }

    /**
     * Makes a login role with the app role's attributes and CONNECT on the checkout's database, and
     * points the doctor's connection at it.
     */
    public static function login(): string
    {
        $role = self::name();
        $password = bin2hex(random_bytes(16));
        $superuser = self::superuser();
        $superuser->statement(sprintf(
            "create role \"%s\" login nosuperuser nocreatedb nocreaterole noreplication nobypassrls password '%s'",
            $role,
            $password,
        ));
        $superuser->statement(sprintf('grant connect on database "%s" to "%s"', $superuser->getDatabaseName(), $role));

        config([
            'database.connections.'.self::LOGIN_CONNECTION => array_merge((array) config('database.connections.pgsql'), [
                'username' => $role,
                'password' => $password,
            ]),
            'cms.doctor.connection' => self::LOGIN_CONNECTION,
        ]);

        return $role;
    }

    public static function drop(): void
    {
        DB::purge(DoctorConnection::NAME);
        DB::purge(self::LOGIN_CONNECTION);

        if (self::$roles === []) {
            return;
        }

        $superuser = self::superuser();

        // DROP OWNED drops the schemas a role owns and revokes its grants in this database and on
        // the database itself, so the role can be dropped.
        foreach (array_reverse(self::$roles) as $role) {
            $superuser->statement(sprintf('drop owned by "%s"', $role));
            $superuser->statement(sprintf('drop role if exists "%s"', $role));
        }

        self::$roles = [];
        DB::purge(self::CONNECTION);
    }

    private static function name(): string
    {
        $role = 'cms_doctor_scratch_'.bin2hex(random_bytes(6));
        self::$roles[] = $role;

        return $role;
    }

    private static function env(string $key): string
    {
        $value = Env::get($key);

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("{$key} is not set; phpunit.xml names the superuser of compose.yaml.");
        }

        return $value;
    }
}

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
    // which owns the schema public.
    expect($postgres->role()->memberships)->toEqual([
        new RoleMembership($owner, superuser: false, bypassRowSecurity: false, ownsRelations: true, createRole: false, createsObjects: true),
        new RoleMembership('pg_database_owner', superuser: false, bypassRowSecurity: false, ownsRelations: false, createRole: false, createsObjects: true),
    ])
        ->and($privileges->ownerRoles)->toBe([$owner])
        ->and($privileges->ownedCount)->toBeGreaterThan(6)
        ->and($privileges->ownedRelations)->toHaveCount(5)
        ->and($privileges->ownedRelations)->toBe(array_values(array_unique($privileges->ownedRelations)));

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which owns relations and owns or may create objects in the database or its schemas; pg_database_owner, which owns or may create objects in the database or its schemas.', $role, $owner))
        ->and($appRole->fix)->toStartWith(sprintf('Run REVOKE %s FROM %s as a superuser', $owner, $role))
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

it('fails postgres.app_role for an app role that is a member of a predefined role that reaches every table or the server', function (string $predefined, string $gives): void {
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
]);

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

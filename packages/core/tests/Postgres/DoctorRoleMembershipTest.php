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
 * granted the owner role, a BYPASSRLS role or a superuser role. Roles belong to the cluster, not
 * to the checkout's database, so each test makes roles of its own under random names and drops
 * them afterwards.
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

        foreach (array_reverse(self::$roles) as $role) {
            $superuser->statement(sprintf('revoke all on database "%s" from "%s"', $superuser->getDatabaseName(), $role));
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

    expect($postgres->role()->memberships)->toEqual([new RoleMembership($owner, false, false, true)])
        ->and($privileges->ownerRoles)->toBe([$owner])
        ->and($privileges->ownedCount)->toBeGreaterThan(6)
        ->and($privileges->ownedRelations)->toHaveCount(5)
        ->and($privileges->ownedRelations)->toBe(array_values(array_unique($privileges->ownedRelations)));

    [$appRole, $ddl] = doctorRoleChecks();

    expect($appRole->status)->toBe(CheckStatus::Fail)
        ->and($appRole->failure)->toBe(FailureKind::Violation)
        ->and($appRole->code)->toBe(AppRoleCheck::CODE_MEMBERSHIP)
        ->and($appRole->cause)->toBe(sprintf('The role %s is a member of %s, which owns relations.', $role, $owner))
        ->and($appRole->fix)->toContain(sprintf('REVOKE %s FROM %s', $owner, $role))
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

<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Tests\Postgres\ScratchRoles;
use Cbox\Cms\Identity\Doctor\Adapter\ConnectionCredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Domain\Checks\Argon2idCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\CredentialIsolationCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\IdentityConnectionCheck;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Closure;
use Illuminate\Support\Facades\DB;

/*
 * The identity module's checks of cms:doctor on the Postgres 18 service of compose.yaml, with the
 * real probes (PRD 5.16, "Lokale konti"): they pass on the credential store as database.sql and
 * the migrations make it, identity.credential_isolation fails once the app role may reach the
 * store, and identity.connection fails once the identity connection logs in as the app role. A
 * grant to the app role is made in this checkout's own test database and taken back after the
 * test; scratch roles are made under random names and dropped.
 */

afterEach(function (): void {
    DB::purge(ConnectionCredentialStoreProbe::IDENTITY_CONNECTION);
    DB::purge(DoctorConnection::NAME);
    ScratchRoles::drop();
});

/**
 * @return array{connection: CheckResult, isolation: CheckResult, argon2id: CheckResult}
 */
function identityChecks(): array
{
    $store = app(CredentialStoreProbe::class);

    return [
        'connection' => new IdentityConnectionCheck($store, app(DoctorSettings::class))->run(),
        'isolation' => new CredentialIsolationCheck($store)->run(),
        'argon2id' => new Argon2idCheck(app(PasswordHashingProbe::class))->run(),
    ];
}

/**
 * Runs the callback with the grants given to the app role as the owner role, and takes them back.
 *
 * @param  list<string>  $grants  the privileges and objects, such as "usage on schema cms_identity"
 * @param  Closure(): void  $callback
 */
function withAppGrants(array $grants, string $grantee, Closure $callback): void
{
    $owner = DB::connection('pgsql_owner');

    try {
        foreach ($grants as $grant) {
            $owner->statement(sprintf('grant %s to %s', $grant, $grantee));
        }

        $callback();
    } finally {
        foreach ($grants as $grant) {
            $owner->statement(sprintf('revoke %s from %s', $grant, $grantee));
        }
    }
}

it('passes identity.connection, identity.credential_isolation and identity.argon2id on the store the migrations made', function (): void {
    $checks = identityChecks();

    expect($checks['connection']->status)->toBe(CheckStatus::Pass, (string) $checks['connection']->cause)
        ->and($checks['connection']->explanation)->toContain('cms_identity@')
        ->and($checks['isolation']->status)->toBe(CheckStatus::Pass, (string) $checks['isolation']->cause)
        ->and($checks['argon2id']->status)->toBe(CheckStatus::Pass, (string) $checks['argon2id']->cause)
        ->and(app(CredentialStoreProbe::class)->appPrivileges())->toBe([]);
});

/**
 * @param  array<mixed>  $given  the grants of the data set
 * @param  array<mixed>  $listed  the privileges of the app role the probe must list
 */
function expectReadableStore(array $given, string $grantee, array $listed): void
{
    $grants = array_values(array_filter($given, is_string(...)));
    $privileges = array_values(array_filter($listed, is_string(...)));

    withAppGrants($grants, $grantee, function () use ($privileges): void {
        $isolation = identityChecks()['isolation'];

        expect([$isolation->status, $isolation->failure, $isolation->code])->toBe([CheckStatus::Fail, FailureKind::Violation, CredentialIsolationCheck::CODE_READABLE])
            ->and(app(CredentialStoreProbe::class)->appPrivileges())->toBe($privileges)
            ->and($isolation->cause)->toContain(implode(', ', $privileges));
    });
}

it('fails identity.credential_isolation with its catalog code once the app role is granted the store', function (array $grants, string $grantee, array $privileges): void {
    expectReadableStore($grants, $grantee, $privileges);
})->with([
    'SELECT on the schema\'s tables' => [['select on all tables in schema cms_identity'], 'cms_app', ['SELECT on cms_identity.local_accounts', 'SELECT on cms_identity.password_reset_tokens']],
    'USAGE on the schema' => [['usage on schema cms_identity'], 'cms_app', ['USAGE on schema cms_identity']],
    'one column, to PUBLIC' => [['select (login) on cms_identity.local_accounts'], 'public', ['SELECT on cms_identity.local_accounts']],
]);

it('fails identity.connection once the identity connection logs in as the app role', function (): void {
    config([
        'database.connections.pgsql_identity.username' => config('database.connections.pgsql.username'),
        'database.connections.pgsql_identity.password' => config('database.connections.pgsql.password'),
    ]);

    $connection = identityChecks()['connection'];

    expect([$connection->status, $connection->failure, $connection->code])->toBe([CheckStatus::Fail, FailureKind::Violation, IdentityConnectionCheck::CODE_SHARED_ROLE])
        ->and($connection->cause)->toContain('logs in as cms_app, the app role');
});

it('fails identity.connection once the identity connection logs in as the owner role', function (): void {
    config([
        'database.connections.pgsql_identity.username' => config('database.connections.pgsql_owner.username'),
        'database.connections.pgsql_identity.password' => config('database.connections.pgsql_owner.password'),
    ]);

    expect(identityChecks()['connection']->code)->toBe(IdentityConnectionCheck::CODE_SHARED_ROLE);
});

it('fails identity.credential_isolation for an identity role that is a member of a privileged role', function (string $attributes, string $cause): void {
    $role = 'cms_identity_scratch_'.bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(16));
    ScratchRoles::$roles[] = $role;
    $superuser = ScratchRoles::superuser();
    $superuser->statement(sprintf("create role \"%s\" login nosuperuser nocreatedb nocreaterole noreplication nobypassrls password '%s'", $role, $password));
    $superuser->statement(sprintf('grant connect on database "%s" to "%s"', $superuser->getDatabaseName(), $role));
    $superuser->statement(sprintf('grant %s to "%s"', $attributes, $role));
    config([
        'database.connections.pgsql_identity.username' => $role,
        'database.connections.pgsql_identity.password' => $password,
    ]);

    $isolation = identityChecks()['isolation'];

    expect([$isolation->status, $isolation->code])->toBe([CheckStatus::Fail, CredentialIsolationCheck::CODE_PRIVILEGED])
        ->and($isolation->cause)->toContain($cause);
})->with([
    'pg_read_all_data' => ['pg_read_all_data', 'is a member of pg_read_all_data'],
    'the owner role' => ['cms_owner', 'is a member of cms_owner'],
]);

it('fails identity.connection with the catalog code of a refused login', function (): void {
    config(['database.connections.pgsql_identity.password' => 'wrong-'.bin2hex(random_bytes(4))]);

    $connection = identityChecks()['connection'];

    expect([$connection->status, $connection->failure, $connection->code])->toBe([CheckStatus::Fail, FailureKind::Violation, IdentityConnectionCheck::CODE_REFUSED]);
});

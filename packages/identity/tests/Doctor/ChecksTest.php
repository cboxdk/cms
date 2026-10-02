<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Identity\Doctor\Adapter\PhpPasswordHashingProbe;
use Cbox\Cms\Identity\Doctor\Domain\Checks\Argon2idCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\CredentialIsolationCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\IdentityConnectionCheck;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeCredentialStoreProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakePasswordHashingProbe;

/*
 * The identity module's checks of cms:doctor (PRD 5.16, "Lokale konti"): each failure with its
 * kind and its catalog code, on fake probes.
 */

/**
 * @param  callable(FakeCredentialStoreProbe): void  $change
 */
function credentialStore(callable $change): FakeCredentialStoreProbe
{
    $probe = new FakeCredentialStoreProbe;
    $change($probe);

    return $probe;
}

/**
 * @return array{0: CheckStatus, 1: ?FailureKind, 2: ?string}
 */
function verdict(CheckResult $result): array
{
    return [$result->status, $result->failure, $result->code];
}

it('passes identity.connection for a role of its own and names the target', function (): void {
    $result = new IdentityConnectionCheck(new FakeCredentialStoreProbe, DoctorSettingsFixture::settings())->run();

    expect(verdict($result))->toBe([CheckStatus::Pass, null, null])
        ->and($result->explanation)->toContain('cms_identity@fake:5432/cms');
});

it('fails identity.connection when the identity connection logs in as the app role or an owner role', function (string $login, ?string $ownerRole, ?string $schemaOwner, string $which): void {
    $probe = credentialStore(static function (FakeCredentialStoreProbe $probe) use ($login, $schemaOwner): void {
        $probe->identityLogin = $login;
        $probe->schemaOwner = $schemaOwner;
    });
    $result = new IdentityConnectionCheck($probe, DoctorSettingsFixture::settings($ownerRole))->run();

    expect(verdict($result))->toBe([CheckStatus::Fail, FailureKind::Violation, IdentityConnectionCheck::CODE_SHARED_ROLE])
        ->and($result->cause)->toContain("logs in as {$login}, the {$which}");
})->with([
    'the app role' => ['cms_app', 'cms_owner', 'cms_owner', 'app role'],
    'the owner role the doctor names' => ['cms_owner', 'cms_owner', 'someone_else', 'owner role'],
    'the owner of the schema' => ['cms_owner', null, 'cms_owner', 'owner role'],
]);

it('fails identity.connection with the kind of the login failure', function (ProbeFailed $failure, FailureKind $kind, string $code): void {
    $probe = credentialStore(static function (FakeCredentialStoreProbe $probe) use ($failure): void {
        $probe->loginFailure = $failure;
    });

    expect(verdict(new IdentityConnectionCheck($probe, DoctorSettingsFixture::settings())->run()))->toBe([CheckStatus::Fail, $kind, $code]);
})->with([
    'unreachable' => [ProbeFailed::unavailable('Connection refused'), FailureKind::Unavailable, IdentityConnectionCheck::CODE_UNAVAILABLE],
    'refused' => [ProbeFailed::violation('password authentication failed'), FailureKind::Violation, IdentityConnectionCheck::CODE_REFUSED],
]);

it('fails identity.connection when a query after the login fails', function (): void {
    $probe = credentialStore(static function (FakeCredentialStoreProbe $probe): void {
        $probe->queryFailure = ProbeFailed::unavailable('server closed the connection');
    });

    expect(verdict(new IdentityConnectionCheck($probe, DoctorSettingsFixture::settings())->run()))->toBe([CheckStatus::Fail, FailureKind::Unavailable, PostgresQueryFailure::CODE]);
});

it('passes identity.credential_isolation for an isolated store and an unprivileged identity role', function (): void {
    expect(verdict(new CredentialIsolationCheck(new FakeCredentialStoreProbe)->run()))->toBe([CheckStatus::Pass, null, null]);
});

it('fails identity.credential_isolation with its code for each break of the isolation', function (callable $change, string $code, string $cause): void {
    $result = new CredentialIsolationCheck(credentialStore($change))->run();

    expect(verdict($result))->toBe([CheckStatus::Fail, FailureKind::Violation, $code])
        ->and($result->cause)->toContain($cause);
})->with([
    'a missing schema' => [static function (FakeCredentialStoreProbe $probe): void {
        $probe->schemaOwner = null;
        $probe->appPrivileges = ['SELECT on cms_identity.local_accounts'];
    }, CredentialIsolationCheck::CODE_MISSING, 'no schema cms_identity'],
    'the app role reaching the store' => [static function (FakeCredentialStoreProbe $probe): void {
        $probe->appPrivileges = ['SELECT on cms_identity.local_accounts', 'USAGE on schema cms_identity'];
    }, CredentialIsolationCheck::CODE_READABLE, 'SELECT on cms_identity.local_accounts, USAGE on schema cms_identity'],
    'a superuser' => [static function (FakeCredentialStoreProbe $probe): void {
        $probe->superuser = true;
    }, CredentialIsolationCheck::CODE_PRIVILEGED, 'cms_identity has SUPERUSER'],
    'BYPASSRLS and CREATEROLE' => [static function (FakeCredentialStoreProbe $probe): void {
        $probe->bypassRowSecurity = true;
        $probe->createRole = true;
    }, CredentialIsolationCheck::CODE_PRIVILEGED, 'has BYPASSRLS and CREATEROLE'],
    'a privileged membership' => [static function (FakeCredentialStoreProbe $probe): void {
        $probe->memberships = [new RoleMembership('pg_read_all_data', false, false, false, false, false)];
    }, CredentialIsolationCheck::CODE_PRIVILEGED, 'is a member of pg_read_all_data'],
]);

it('fails identity.credential_isolation when a query fails', function (): void {
    $probe = credentialStore(static function (FakeCredentialStoreProbe $probe): void {
        $probe->queryFailure = ProbeFailed::violation('permission denied for table pg_authid');
    });

    expect(verdict(new CredentialIsolationCheck($probe)->run()))->toBe([CheckStatus::Fail, FailureKind::Violation, PostgresQueryFailure::CODE]);
});

it('passes identity.argon2id with Argon2id and fails without it', function (): void {
    expect(verdict(new Argon2idCheck(new FakePasswordHashingProbe)->run()))->toBe([CheckStatus::Pass, null, null])
        ->and(verdict(new Argon2idCheck(new FakePasswordHashingProbe(argon2id: false))->run()))->toBe([CheckStatus::Fail, FailureKind::Violation, Argon2idCheck::CODE]);
});

it('blocks the kernel on every identity check, and orders them after postgres.reachable and each other', function (): void {
    $connection = new IdentityConnectionCheck(new FakeCredentialStoreProbe, DoctorSettingsFixture::settings());
    $isolation = new CredentialIsolationCheck(new FakeCredentialStoreProbe);
    $argon2id = new Argon2idCheck(new FakePasswordHashingProbe);

    expect([$connection->blocking(), $isolation->blocking(), $argon2id->blocking()])->toBe([true, true, true])
        ->and($connection->requires())->toEqual([new CheckId(PostgresReachableCheck::ID)])
        ->and($isolation->requires())->toEqual([new CheckId(PostgresReachableCheck::ID), new CheckId(IdentityConnectionCheck::ID)])
        ->and($argon2id->requires())->toBe([])
        ->and(array_map(ErrorCode::tryFrom(...), [
            IdentityConnectionCheck::CODE_UNAVAILABLE, IdentityConnectionCheck::CODE_REFUSED, IdentityConnectionCheck::CODE_SHARED_ROLE,
            CredentialIsolationCheck::CODE_MISSING, CredentialIsolationCheck::CODE_READABLE, CredentialIsolationCheck::CODE_PRIVILEGED,
            Argon2idCheck::CODE,
        ]))->not->toContain(null);
});

it('finds Argon2id in this PHP, as the dev image and CI run it', function (): void {
    expect(new PhpPasswordHashingProbe()->argon2id())->toBe(defined('PASSWORD_ARGON2ID'))
        ->and(defined('PASSWORD_ARGON2ID'))->toBeTrue();
});

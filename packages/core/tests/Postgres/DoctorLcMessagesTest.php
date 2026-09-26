<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionLcMessagesProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleLcMessages;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Illuminate\Support\Facades\DB;

/*
 * postgres.lc_messages on the Postgres 18 service of compose.yaml (PRD 4.2): the probe the core
 * binds reads lc_messages as the app role on the doctor's connection and as the owner role on the
 * doctor's copy of pgsql_owner, and the check passes on the roles the init script provisions.
 */

afterEach(function (): void {
    DB::purge(DoctorConnection::NAME);
    DB::purge(DoctorConnection::OWNER_NAME);
});

/**
 * The owner role's connection with changes, registered as the doctor's owner connection.
 *
 * @param  array<string, mixed>  $changes
 */
function ownerConnectionWith(array $changes): void
{
    config([
        'database.connections.pgsql_owner_changed' => array_merge((array) config('database.connections.pgsql_owner'), $changes),
        'cms.doctor.owner_connection' => 'pgsql_owner_changed',
    ]);
}

it('reads lc_messages C from the role for the app role and the owner role, and C for the process', function (): void {
    $probe = app(LcMessagesProbe::class);

    expect($probe)->toBeInstanceOf(ConnectionLcMessagesProbe::class)
        ->and($probe->appRole())->toEqual(new RoleLcMessages('cms_app', 'pgsql', 'C', 'user'))
        ->and($probe->ownerRole())->toEqual(new RoleLcMessages('cms_owner', 'pgsql_owner', 'C', 'user'))
        ->and($probe->process())->toBe('C');
});

it('passes on the provisioned roles', function (): void {
    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe('Messages are English: lc_messages is C for the role cms_app and C for the role cms_owner, and LC_MESSAGES of the PHP process is C.');
});

it('reports an owner connection that refuses the login as a violation naming the connection', function (): void {
    ownerConnectionWith(['password' => 'not-the-password']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(PostgresQueryFailure::CODE)
        ->and($result->cause)->toStartWith('On the connection pgsql_owner_changed: ')
        ->and($result->cause)->toContain('password authentication failed for user "cms_owner"');
});

it('reports an owner connection on a closed port as unavailable', function (): void {
    ownerConnectionWith(['port' => 1]);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->failure)->toBe(FailureKind::Unavailable)
        ->and($result->cause)->toStartWith('On the connection pgsql_owner_changed: ')
        ->and($result->cause)->toContain('port 1 failed');
});

it('reports an owner connection that is not configured as a violation', function (): void {
    config(['cms.doctor.owner_connection' => 'pgsql_owner_missing']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->failure)->toBe(FailureKind::Violation)
        ->and($result->cause)->toBe('On the connection pgsql_owner_missing: The database connection pgsql_owner_missing is not configured as a Postgres connection (driver pgsql) in config/database.php.');
});

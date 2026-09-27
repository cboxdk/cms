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
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/*
 * postgres.lc_messages on the Postgres 18 service of compose.yaml (PRD 4.2): the probe the core
 * binds reads lc_messages as the app role on the doctor's connection, and the owner role's from
 * the catalog on the same connection, without logging in as the owner role, and the check passes
 * on the roles the init script provisions. Scratch roles, made by the superuser of compose.yaml
 * under random names and dropped afterwards, give an owner role settings the provisioned ones do
 * not have.
 */

afterEach(function (): void {
    LcMessagesRoles::drop();
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
        'cbox-cms.doctor.owner_connection' => 'pgsql_owner_changed',
    ]);
}

it('reads lc_messages C from the role for the app role and the owner role, and C for the process', function (): void {
    $probe = app(LcMessagesProbe::class);

    expect($probe)->toBeInstanceOf(ConnectionLcMessagesProbe::class)
        ->and($probe->appRole())->toEqual(new RoleLcMessages('cms_app', 'pgsql', 'C', SettingSource::User))
        ->and($probe->ownerRole())->toEqual(new RoleLcMessages('cms_owner', 'pgsql', 'C', SettingSource::User))
        ->and($probe->process())->toBe('C');
});

it('passes on the provisioned roles', function (): void {
    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe('Messages are English: lc_messages is C for the role cms_app and C for the role cms_owner, and LC_MESSAGES of the PHP process is C.');
});

it('never logs in as the owner role: a wrong owner password in this process leaves the check passing', function (): void {
    ownerConnectionWith(['password' => 'not-the-password']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and(array_keys(DB::getConnections()))->not->toContain('pgsql_owner_changed', 'cms_doctor_owner');
});

it('reads the owner role that cbox-cms.doctor.owner_role names in a process without the owner connection', function (): void {
    config(['cbox-cms.doctor.owner_connection' => 'pgsql_owner_missing', 'cbox-cms.doctor.owner_role' => 'cms_owner']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe('Messages are English: lc_messages is C for the role cms_app and C for the role cms_owner, and LC_MESSAGES of the PHP process is C.');
});

it('reports an owner role it cannot name as a violation', function (): void {
    config(['cbox-cms.doctor.owner_connection' => 'pgsql_owner_missing']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(PostgresQueryFailure::CODE)
        ->and($result->cause)->toBe('The owner role is not known: cbox-cms.doctor.owner_role is null and this process has no owner connection with a username.');
});

it('reports an owner role that does not exist as a violation', function (): void {
    config(['cbox-cms.doctor.owner_role' => 'cms_no_such_owner']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->failure)->toBe(FailureKind::Violation)
        ->and($result->cause)->toBe('The owner role cms_no_such_owner does not exist in the database of the connection pgsql.');
});

it('reports an app connection on a closed port as unavailable, naming the connection', function (): void {
    config(['database.connections.pgsql_closed' => array_merge((array) config('database.connections.pgsql'), ['port' => 1])]);

    try {
        new ConnectionLcMessagesProbe(new DoctorConnection(app(DatabaseManager::class), app(Repository::class), 'pgsql_closed', DoctorConnection::NAME, 3), 'cms_owner')->ownerRole();
    } catch (ProbeFailed $failed) {
        expect($failed->kind)->toBe(FailureKind::Unavailable)
            ->and($failed->cause)->toStartWith('On the connection pgsql_closed: ');

        return;
    }

    throw new UnexpectedValueException('Expected the probe to fail on a closed port.');
});

it('takes the owner role\'s setting for this database over its own, as a new session does', function (): void {
    // The server has the locales C, C.utf8, en_US.utf8 and POSIX, and lc_messages accepts only an
    // installed one, so the values differ without being foreign; LcMessagesCheckTest has those.
    $owner = LcMessagesRoles::owner(
        "alter role \"%1\$s\" set lc_messages = 'POSIX'",
        "alter role \"%1\$s\" in database \"%2\$s\" set lc_messages = 'en_US.utf8'",
    );
    config(['cbox-cms.doctor.owner_role' => $owner]);

    expect(app(LcMessagesProbe::class)->ownerRole())->toEqual(new RoleLcMessages($owner, 'pgsql', 'en_US.utf8', SettingSource::DatabaseUser));
});

it('reads the owner role\'s own setting from the catalog when it has no setting for this database', function (): void {
    $owner = LcMessagesRoles::owner("alter role \"%1\$s\" set lc_messages = 'POSIX'");
    config(['cbox-cms.doctor.owner_role' => $owner]);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect(app(LcMessagesProbe::class)->ownerRole())->toEqual(new RoleLcMessages($owner, 'pgsql', 'POSIX', SettingSource::User))
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe(sprintf('Messages are English: lc_messages is C for the role cms_app and POSIX for the role %s, and LC_MESSAGES of the PHP process is C.', $owner));
});

it('reports an owner role without a setting as a violation when the app role\'s own setting hides the server default', function (): void {
    $owner = LcMessagesRoles::owner();
    config(['cbox-cms.doctor.owner_role' => $owner]);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->failure)->toBe(FailureKind::Violation)
        ->and($result->cause)->toBe(sprintf('The owner role %s has no lc_messages of its own, of the database or of ALTER ROLE ALL, so it gets the server\'s default, which the app role cannot read: its own lc_messages comes from "user".', $owner));
});

it('gives an owner role without a setting the server default when the app role\'s session shows it', function (): void {
    $owner = LcMessagesRoles::owner();
    $app = LcMessagesRoles::appWithoutSettings();
    config(['cbox-cms.doctor.owner_role' => $owner]);

    $probe = app(LcMessagesProbe::class);
    $session = $probe->appRole();

    expect($session->role)->toBe($app)
        ->and($session->source)->toBeIn([SettingSource::Default, SettingSource::ConfigurationFile, SettingSource::CommandLine, SettingSource::EnvironmentVariable])
        ->and($probe->ownerRole())->toEqual(new RoleLcMessages($owner, LcMessagesRoles::LOGIN_CONNECTION, $session->value, $session->source));
});

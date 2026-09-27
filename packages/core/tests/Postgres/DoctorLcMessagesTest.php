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
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Env;
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

/**
 * The scratch roles of one test and the superuser's connection that makes and drops them.
 */
final class LcMessagesRoles
{
    public const string CONNECTION = 'pgsql_lc_messages_superuser';

    public const string LOGIN_CONNECTION = 'pgsql_lc_messages_scratch';

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
     * Makes a role that cannot log in, runs the statements with %1$s as its name, and returns the name.
     */
    public static function owner(string ...$statements): string
    {
        $role = self::name();
        $superuser = self::superuser();
        $superuser->statement(sprintf('create role "%s" nologin', $role));

        foreach ($statements as $statement) {
            $superuser->statement(sprintf($statement, $role, $superuser->getDatabaseName()));
        }

        return $role;
    }

    /**
     * Makes a login role with no settings of its own and points the doctor's app connection at it.
     */
    public static function appWithoutSettings(): string
    {
        $role = self::name();
        $password = bin2hex(random_bytes(16));
        $superuser = self::superuser();
        $superuser->statement(sprintf("create role \"%s\" login password '%s'", $role, $password));
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

        // DROP OWNED revokes the role's grants in this database and on the database itself, and
        // DROP ROLE removes its settings in pg_db_role_setting.
        foreach (array_reverse(self::$roles) as $role) {
            $superuser->statement(sprintf('drop owned by "%s"', $role));
            $superuser->statement(sprintf('drop role if exists "%s"', $role));
        }

        self::$roles = [];
        DB::purge(self::CONNECTION);
    }

    private static function name(): string
    {
        $role = 'cms_lc_scratch_'.bin2hex(random_bytes(6));
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
        'cms.doctor.owner_connection' => 'pgsql_owner_changed',
    ]);
}

it('reads lc_messages C from the role for the app role and the owner role, and C for the process', function (): void {
    $probe = app(LcMessagesProbe::class);

    expect($probe)->toBeInstanceOf(ConnectionLcMessagesProbe::class)
        ->and($probe->appRole())->toEqual(new RoleLcMessages('cms_app', 'pgsql', 'C', 'user'))
        ->and($probe->ownerRole())->toEqual(new RoleLcMessages('cms_owner', 'pgsql', 'C', 'user'))
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

it('reads the owner role that cms.doctor.owner_role names in a process without the owner connection', function (): void {
    config(['cms.doctor.owner_connection' => 'pgsql_owner_missing', 'cms.doctor.owner_role' => 'cms_owner']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe('Messages are English: lc_messages is C for the role cms_app and C for the role cms_owner, and LC_MESSAGES of the PHP process is C.');
});

it('reports an owner role it cannot name as a violation', function (): void {
    config(['cms.doctor.owner_connection' => 'pgsql_owner_missing']);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(PostgresQueryFailure::CODE)
        ->and($result->cause)->toBe('The owner role is not known: cms.doctor.owner_role is null and this process has no owner connection with a username.');
});

it('reports an owner role that does not exist as a violation', function (): void {
    config(['cms.doctor.owner_role' => 'cms_no_such_owner']);

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
    config(['cms.doctor.owner_role' => $owner]);

    expect(app(LcMessagesProbe::class)->ownerRole())->toEqual(new RoleLcMessages($owner, 'pgsql', 'en_US.utf8', 'database user'));
});

it('reads the owner role\'s own setting from the catalog when it has no setting for this database', function (): void {
    $owner = LcMessagesRoles::owner("alter role \"%1\$s\" set lc_messages = 'POSIX'");
    config(['cms.doctor.owner_role' => $owner]);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect(app(LcMessagesProbe::class)->ownerRole())->toEqual(new RoleLcMessages($owner, 'pgsql', 'POSIX', 'user'))
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe(sprintf('Messages are English: lc_messages is C for the role cms_app and POSIX for the role %s, and LC_MESSAGES of the PHP process is C.', $owner));
});

it('reports an owner role without a setting as a violation when the app role\'s own setting hides the server default', function (): void {
    $owner = LcMessagesRoles::owner();
    config(['cms.doctor.owner_role' => $owner]);

    $result = new LcMessagesCheck(app(LcMessagesProbe::class))->run();

    expect($result->failure)->toBe(FailureKind::Violation)
        ->and($result->cause)->toBe(sprintf('The owner role %s has no lc_messages of its own, of the database or of ALTER ROLE ALL, so it gets the server\'s default, which the app role cannot read: its own lc_messages comes from "user".', $owner));
});

it('gives an owner role without a setting the server default when the app role\'s session shows it', function (): void {
    $owner = LcMessagesRoles::owner();
    $app = LcMessagesRoles::appWithoutSettings();
    config(['cms.doctor.owner_role' => $owner]);

    $probe = app(LcMessagesProbe::class);
    $session = $probe->appRole();

    expect($session->role)->toBe($app)
        ->and($session->source)->toBeIn(['default', 'configuration file', 'command line', 'environment variable'])
        ->and($probe->ownerRole())->toEqual(new RoleLcMessages($owner, LcMessagesRoles::LOGIN_CONNECTION, $session->value, $session->source));
});

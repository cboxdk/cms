<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

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
            'cbox-cms.doctor.connection' => self::LOGIN_CONNECTION,
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

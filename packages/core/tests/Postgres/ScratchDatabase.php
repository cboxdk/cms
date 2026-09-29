<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Illuminate\Support\Facades\DB;

/**
 * A database of one test that the owner role creates next to the checkout's own and drops after
 * it, for checks of a database the core's migrations have not run on.
 */
final class ScratchDatabase
{
    public static ?string $name = null;

    /**
     * Creates the database, empty, as the owner role, and returns its name.
     */
    public static function create(): string
    {
        $name = 'cms_scratch_'.bin2hex(random_bytes(8));
        DB::connection('pgsql_owner')->statement(sprintf('create database "%s"', $name));

        return self::$name = $name;
    }

    /**
     * A connection like the configured one, registered under the name given, to the database.
     */
    public static function connection(string $name, string $like): void
    {
        config(['database.connections.'.$name => array_merge((array) config('database.connections.'.$like), ['database' => self::$name])]);
    }

    public static function drop(): void
    {
        if (self::$name !== null) {
            DB::connection('pgsql_owner')->statement(sprintf('drop database if exists "%s" with (force)', self::$name));
            self::$name = null;
        }
    }
}

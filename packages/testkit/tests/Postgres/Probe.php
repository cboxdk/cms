<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the harness's own Postgres tests.
 */
final class Probe
{
    /** A table the owner role creates and the app role writes. */
    public const string TABLE = 'harness_probe';

    /**
     * The owner connection, as the harness uses it.
     */
    public static function owner(): Connection
    {
        return self::connection('pgsql_owner');
    }

    /**
     * The default connection, which is the app role.
     */
    public static function app(): Connection
    {
        return self::connection(null);
    }

    /**
     * Creates the probe table as the owner if it does not exist and returns its name. It stays
     * between tests, so the harness's truncation empties it; the next process's migrate:fresh
     * drops it.
     */
    public static function table(): string
    {
        self::owner()->statement('create table if not exists '.self::TABLE.' (id bigserial primary key, note text not null)');

        return self::TABLE;
    }

    private static function connection(?string $name): Connection
    {
        return DB::connection($name);
    }
}

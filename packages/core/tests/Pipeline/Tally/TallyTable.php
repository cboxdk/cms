<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The scratch table of the test-only tally aggregate, `tally_counters`, made by the owner role:
 * each tally's id, version and total. The owner's default privileges give the app role SELECT,
 * INSERT, UPDATE and DELETE, and the table has no row level security, so the command reads and
 * writes it as the app role. Tests create it in set-up and drop it in tear-down.
 */
final readonly class TallyTable
{
    public const string TABLE = 'tally_counters';

    /** The kind of the tally aggregate: the prefix of its key and the aggregate type of its events. */
    public const string KIND = 'tally';

    public static function create(): void
    {
        self::drop();
        self::owner()->statement('create table tally_counters (id uuid primary key, version bigint not null check (version >= 1), total bigint not null)');
    }

    public static function drop(): void
    {
        self::owner()->statement('drop table if exists tally_counters');
    }

    /**
     * A tally at the version with the total, written as the owner role in a transaction of its own,
     * as another session would.
     */
    public static function put(TallyId $tally, int $version, int $total): void
    {
        self::owner()->statement(
            'insert into tally_counters (id, version, total) values (?, ?, ?) on conflict (id) do update set version = excluded.version, total = excluded.total',
            [$tally->toString(), $version, $total],
        );
    }

    /**
     * The tally's version and total, read as the owner role, or null when it does not exist.
     *
     * @return array{int, int}|null
     */
    public static function row(TallyId $tally): ?array
    {
        $row = self::owner()->selectOne('select version, total from tally_counters where id = ?', [$tally->toString()]);

        if (! is_object($row)) {
            return null;
        }

        $version = property_exists($row, 'version') ? $row->version : null;
        $total = property_exists($row, 'total') ? $row->total : null;

        if (! is_int($version) || ! is_int($total)) {
            throw new LogicException('A tally row has an integer version and total.');
        }

        return [$version, $total];
    }

    /**
     * The tally's version, read on the app connection, inside the command transaction when one is open.
     */
    public function version(TallyId $tally): ?AggregateVersion
    {
        $version = DB::connection()->scalar('select version from tally_counters where id = ?', [$tally->toString()]);

        return is_int($version) ? new AggregateVersion($version) : null;
    }

    private static function owner(): Connection
    {
        return DB::connection('pgsql_owner');
    }
}

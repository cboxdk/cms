<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;

/**
 * Scratch partitioned tables for the partition manager's Postgres tests, made by the owner role.
 *
 * `partition_scratch` is partitioned on a UUIDv7 id, `partition_scratch_ts` on a timestamptz.
 * `partition_scratch_seq` is partitioned on a bigint id that the sequence `partition_scratch_ids`
 * feeds, with the timestamptz `at` for retention. `partition_scratch_kind` is partitioned by list
 * on `kind` into `partition_scratch_kind_a` and `partition_scratch_kind_b`, each partitioned on a
 * bigint id that the one sequence `partition_scratch_kind_ids` feeds, as `revision_payloads` is
 * by kind (PRD 4.1). Tests create them in set-up and drop them, with every partition, leftover and
 * sequence, in tear-down.
 */
final class PartitionScratch
{
    public const string UUID_TABLE = 'partition_scratch';

    public const string TIME_TABLE = 'partition_scratch_ts';

    public const string SEQUENCE_TABLE = 'partition_scratch_seq';

    public const string SEQUENCE = 'partition_scratch_ids';

    public const string LIST_ROOT = 'partition_scratch_kind';

    public const string LIST_A = 'partition_scratch_kind_a';

    public const string LIST_B = 'partition_scratch_kind_b';

    public const string LIST_SEQUENCE = 'partition_scratch_kind_ids';

    /** @var list<array{connection: string, sql: string}> */
    private static array $statements = [];

    public static function owner(): Connection
    {
        return DB::connection('pgsql_owner');
    }

    public static function app(): Connection
    {
        return DB::connection();
    }

    public static function create(): void
    {
        self::drop();

        self::owner()->statement(sprintf(
            "create table %s (id uuid not null, note text not null default '' check (note <> 'forbidden'), primary key (id)) partition by range (id)",
            self::UUID_TABLE,
        ));
        self::owner()->statement(sprintf(
            'create table %s (at timestamptz not null, value integer not null default 0) partition by range (at)',
            self::TIME_TABLE,
        ));
        self::owner()->statement(sprintf('create sequence %s', self::SEQUENCE));
        self::owner()->statement(sprintf(
            "create table %s (id bigint not null default nextval('%s'), at timestamptz not null) partition by range (id)",
            self::SEQUENCE_TABLE,
            self::SEQUENCE,
        ));
        self::owner()->statement(sprintf('create sequence %s', self::LIST_SEQUENCE));
        self::owner()->statement(sprintf(
            "create table %s (kind text not null, id bigint not null default nextval('%s'), at timestamptz not null) partition by list (kind)",
            self::LIST_ROOT,
            self::LIST_SEQUENCE,
        ));

        foreach ([self::LIST_A => 'a', self::LIST_B => 'b'] as $table => $kind) {
            self::owner()->statement(sprintf("create table %s partition of %s for values in ('%s') partition by range (id)", $table, self::LIST_ROOT, $kind));
        }
    }

    /**
     * Drops every table in the schema whose name starts with `partition_scratch`: the parents
     * with their partitions, and detached leftovers; then every such sequence.
     */
    public static function drop(): void
    {
        $owner = self::owner();
        $owner->statement("set lock_timeout = '5s'");

        foreach (self::names("select relname::text as name from pg_class where relnamespace = 'cms'::regnamespace and relkind in ('p', 'r') and not relispartition and starts_with(relname::text, 'partition_scratch') order by relkind desc") as $table) {
            $owner->statement(sprintf('drop table if exists "%s" cascade', $table));
        }

        foreach (self::names("select relname::text as name from pg_class where relnamespace = 'cms'::regnamespace and relkind = 'S' and starts_with(relname::text, 'partition_scratch') order by relname") as $sequence) {
            $owner->statement(sprintf('drop sequence if exists "%s"', $sequence));
        }

        $owner->statement('reset lock_timeout');
    }

    /**
     * Manages the scratch tables with the given settings, and the policy values given.
     *
     * @param  array<string, array{key: string, interval: string, retention_days: int|null}|array{key: string, width: int, sequence: string, retention_days: int|null, retention_column: string|null}>  $tables
     * @param  array<string, int|string>  $policy  keys of cbox-cms.database.partitions, and owner_connection
     */
    public static function manage(array $tables, array $policy = []): void
    {
        config()->set('cbox-cms.database.partitions.tables', $tables);

        foreach ($policy as $key => $value) {
            config()->set($key === 'owner_connection' ? 'cbox-cms.database.owner_connection' : 'cbox-cms.database.partitions.'.$key, $value);
        }
    }

    /**
     * @param  array{key?: string, interval?: string, retention_days?: int|null}  $settings
     * @return array{key: string, interval: string, retention_days: int|null}
     */
    public static function daily(array $settings = []): array
    {
        return [
            'key' => $settings['key'] ?? 'uuid7',
            'interval' => $settings['interval'] ?? 'day',
            'retention_days' => $settings['retention_days'] ?? null,
        ];
    }

    /**
     * A table partitioned on a sequence, with retention on its `at` column when $retentionDays is
     * given.
     *
     * @return array{key: string, width: int, sequence: string, retention_days: int|null, retention_column: string|null}
     */
    public static function sequenced(int $width, string $sequence = self::SEQUENCE, ?int $retentionDays = null): array
    {
        return [
            'key' => 'bigint',
            'width' => $width,
            'sequence' => $sequence,
            'retention_days' => $retentionDays,
            'retention_column' => $retentionDays === null ? null : 'at',
        ];
    }

    /**
     * Sets the sequence's current value, as the last id it handed out.
     */
    public static function advanceSequence(string $sequence, int $current): void
    {
        self::owner()->select('select setval(?::regclass, ?, true)', [$sequence, $current]);
    }

    /**
     * Writes a row with the id and the time to a table partitioned on a sequence.
     */
    public static function insertRow(string $table, int $id, DateTimeImmutable $at): void
    {
        self::owner()->insert(sprintf('insert into %s (id, at) values (?, ?)', $table), [$id, $at->format('Y-m-d H:i:s.uP')]);
    }

    public static function clockAt(string $instant): FakeClock
    {
        $clock = new FakeClock(new DateTimeImmutable($instant));
        app()->instance(Clock::class, $clock);

        return $clock;
    }

    /**
     * The partitions of a table in pg_inherits, by name.
     *
     * @return list<string>
     */
    public static function partitions(string $parent): array
    {
        return self::names(
            'select c.relname::text as name from pg_inherits i join pg_class c on c.oid = i.inhrelid where i.inhparent = to_regclass(?) order by c.relname',
            [$parent],
        );
    }

    /**
     * Partitions of the table that are pending detach.
     *
     * @return list<string>
     */
    public static function pendingDetach(string $parent): array
    {
        return self::names(
            'select c.relname::text as name from pg_inherits i join pg_class c on c.oid = i.inhrelid where i.inhparent = to_regclass(?) and i.inhdetachpending order by c.relname',
            [$parent],
        );
    }

    /** The number of relations in pg_partition_tree, the root included. */
    public static function treeCount(string $parent): int
    {
        $count = self::owner()->scalar('select count(*) from pg_partition_tree(to_regclass(?))', [$parent]);

        return is_int($count) ? $count : throw new LogicException('Expected a count.');
    }

    public static function exists(string $table): bool
    {
        return self::owner()->scalar('select to_regclass(?) is not null', [$table]) === true;
    }

    public static function isPartition(string $table): bool
    {
        return self::owner()->scalar('select relispartition from pg_class where oid = to_regclass(?)', [$table]) === true;
    }

    public static function ownerOf(string $table): string
    {
        $owner = self::owner()->scalar('select pg_get_userbyid(relowner)::text from pg_class where oid = to_regclass(?)', [$table]);

        return is_string($owner) ? $owner : throw new LogicException('Expected an owner.');
    }

    public static function bounds(string $partition): string
    {
        $bounds = self::owner()->scalar('select pg_get_expr(relpartbound, oid) from pg_class where oid = to_regclass(?)', [$partition]);

        return is_string($bounds) ? $bounds : throw new LogicException('Expected partition bounds.');
    }

    /**
     * The partition a row of the uuid table landed in.
     */
    public static function partitionOfId(string $id): string
    {
        $partition = self::owner()->scalar(sprintf('select tableoid::regclass::text from %s where id = ?', self::UUID_TABLE), [$id]);

        return is_string($partition) ? $partition : throw new LogicException('Expected the row to exist.');
    }

    /**
     * Starts recording every SQL statement on every connection of this test's application.
     * Call it once per test.
     */
    public static function recordStatements(): void
    {
        self::$statements = [];

        Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            self::$statements[] = ['connection' => $query->connectionName, 'sql' => $query->sql];
        });
    }

    /**
     * The statements recorded on a connection, or on all of them.
     *
     * @return list<string>
     */
    public static function statements(?string $connection = null): array
    {
        return array_values(array_map(
            static fn (array $statement): string => $statement['sql'],
            array_filter(self::$statements, static fn (array $statement): bool => $connection === null || $statement['connection'] === $connection),
        ));
    }

    /**
     * @param  list<int|string>  $bindings
     * @return list<string>
     */
    private static function names(string $sql, array $bindings = []): array
    {
        $names = [];

        foreach (self::owner()->select($sql, $bindings) as $row) {
            $name = is_object($row) && property_exists($row, 'name') ? $row->name : null;
            $names[] = is_string($name) ? $name : throw new LogicException('Expected a name.');
        }

        return $names;
    }
}

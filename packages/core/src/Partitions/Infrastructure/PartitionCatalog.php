<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequenceRetention;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use LogicException;

/**
 * What the partition manager reads from the Postgres catalog, on the owner connection, and the
 * doctor on its own connection as the app role.
 */
#[Internal]
final readonly class PartitionCatalog
{
    /** How often the wait for older lockers looks at pg_locks. */
    private const int POLL_MICROSECONDS = 20_000;

    public function __construct(private Connection $connection) {}

    public function role(): string
    {
        return CatalogRow::one($this->connection->select('select current_user::text as role', [], false))->string('role');
    }

    /**
     * Whether the connection's role may create tables in its current schema. The app role may not
     * (PRD 4.2).
     */
    public function canCreateTables(): bool
    {
        return CatalogRow::one($this->connection->select(
            "select coalesce(has_schema_privilege(current_schema(), 'CREATE'), false) as allowed",
            [],
            false,
        ))->bool('allowed');
    }

    /**
     * The managed table in the connection's search path, checked: it exists, it is partitioned
     * by range, and it has no DEFAULT partition. The root of its partition tree comes with it.
     * For a table partitioned on a sequence, the sequence is checked too: it exists, counts up
     * from 0 or more and the connection's role may read it; and so is the retention column, a
     * timestamptz column of the table, when it has one.
     */
    public function table(PartitionedTable|SequencePartitionedTable $table): CatalogTable
    {
        $rows = CatalogRow::all($this->connection->select(
            <<<'SQL'
                select c.oid::bigint as oid,
                       n.nspname::text as schema,
                       c.relkind::text as kind,
                       c.relrowsecurity as row_security,
                       c.relforcerowsecurity as force_row_security,
                       pt.partstrat::text as strategy,
                       d.relname::text as default_partition,
                       rn.nspname::text as root_schema,
                       r.relname::text as root
                from pg_class c
                join pg_namespace n on n.oid = c.relnamespace
                left join pg_partitioned_table pt on pt.partrelid = c.oid
                left join pg_class d on d.oid = pt.partdefid
                left join pg_class r on r.oid = pg_partition_root(c.oid)
                left join pg_namespace rn on rn.oid = r.relnamespace
                where c.oid = to_regclass(?)
                SQL,
            [Sql::identifier($table->name)],
            false,
        ));

        if ($rows === []) {
            throw UnmanageableTable::missing($table->name, $this->connectionName());
        }

        $row = $rows[0];

        if ($row->string('kind') !== 'p' || $row->nullableString('strategy') !== 'r') {
            throw UnmanageableTable::notRangePartitioned($table->name);
        }

        $default = $row->nullableString('default_partition');

        if ($default !== null) {
            throw UnmanageableTable::hasDefaultPartition($table->name, $default);
        }

        $oid = $row->int('oid');

        if ($table instanceof SequencePartitionedTable && $table->retention instanceof SequenceRetention) {
            $this->checkRetentionColumn($oid, $table->name, $table->retention);
        }

        return new CatalogTable(
            table: $table,
            oid: $oid,
            schema: $row->string('schema'),
            rowSecurity: $row->bool('row_security'),
            forceRowSecurity: $row->bool('force_row_security'),
            rootSchema: $row->string('root_schema'),
            root: $row->string('root'),
            qualifiedSequence: $table instanceof SequencePartitionedTable ? Sql::qualified($this->sequenceSchema($table), $table->sequence) : null,
        );
    }

    /**
     * The current value of the sequence of a table partitioned on a sequence: the last id it
     * handed out, or one less than the id it hands out next when it has handed out none since it
     * was created or set with setval(..., false).
     *
     * With a sequence CACHE above 1, the value is the end of the ids handed to the sessions'
     * caches, and a session can still write an id below it.
     */
    public function sequenceValue(CatalogTable $table): int
    {
        $sequence = $table->qualifiedSequence ?? throw new LogicException(sprintf('The table "%s" is not partitioned on a sequence.', $table->table->name));

        return CatalogRow::one($this->connection->select(
            sprintf('select (last_value - case when is_called then 0 else 1 end)::bigint as current from %s', $sequence),
            [],
            false,
        ))->int('current');
    }

    /**
     * The newest value of the retention column in a partition, or null when the partition has
     * no rows.
     *
     * It reads with row_security off, so a table whose row security applies to the owner role
     * makes Postgres refuse the read instead of hiding rows and passing a partition with rows off
     * as empty.
     */
    public function newestRow(CatalogTable $table, string $partition, SequenceRetention $retention): ?DateTimeImmutable
    {
        $this->connection->statement('set row_security = off');

        try {
            $newest = CatalogRow::one($this->connection->select(
                sprintf(
                    'select to_char(max(%s) at time zone \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') as newest from %s',
                    Sql::identifier($retention->column),
                    $table->qualifiedPartition($partition),
                ),
                [],
                false,
            ))->nullableString('newest');
        } finally {
            $this->connection->statement('reset row_security');
        }

        return $newest === null ? null : new DateTimeImmutable($newest);
    }

    /**
     * The managed partitions of the table, by name, in name order: its partitions, and tables in
     * its schema with a managed name that are no longer partitions of anything. Partitions with
     * other names are not managed and not listed.
     *
     * @return list<CatalogPartition>
     */
    public function partitions(CatalogTable $table): array
    {
        $rows = CatalogRow::all($this->connection->select(
            <<<'SQL'
                select c.relname::text as name,
                       i.inhrelid is not null as attached,
                       coalesce(i.inhdetachpending, false) as detach_pending
                from pg_class c
                left join pg_inherits i on i.inhrelid = c.oid and i.inhparent = ?::oid
                where c.relnamespace = (select relnamespace from pg_class where oid = ?::oid)
                  and c.relkind = 'r'
                  and starts_with(c.relname::text, ?)
                  and (i.inhrelid is not null or not c.relispartition)
                order by c.relname
                SQL,
            [$table->oid, $table->oid, $table->table->name.'_p'],
            false,
        ));

        $partitions = [];

        foreach ($rows as $row) {
            $partition = $table->table->partitionNamed($row->string('name'));

            if ($partition === null) {
                continue;
            }

            $partitions[] = new CatalogPartition($partition, match (true) {
                ! $row->bool('attached') => PartitionState::Detached,
                $row->bool('detach_pending') => PartitionState::DetachPending,
                default => PartitionState::Attached,
            });
        }

        return $partitions;
    }

    /**
     * The state of one managed partition, or null when the table is gone.
     */
    public function state(CatalogTable $table, string $partition): ?PartitionState
    {
        foreach ($this->partitions($table) as $found) {
            if ($found->partition->name === $partition) {
                return $found->state;
            }
        }

        return null;
    }

    /**
     * Waits until the transactions that hold or wait for a lock on the table right now have
     * ended, for at most $timeoutMs of real time.
     *
     * DETACH PARTITION CONCURRENTLY commits its first phase, marking the partition detach-pending,
     * and then waits for every transaction that uses the parent. If that wait passes lock_timeout,
     * the partition is left pending. Waiting here first, for the same transactions, lets the
     * manager give up before the detach starts instead of halfway.
     *
     * @throws LockWaitExpired when they have not ended in time
     */
    public function waitForLockers(CatalogTable $table, int $timeoutMs): void
    {
        $lockers = array_map(
            static fn (CatalogRow $row): string => $row->string('vxid'),
            CatalogRow::all($this->connection->select(
                <<<'SQL'
                    select distinct l.virtualtransaction::text as vxid
                    from pg_locks l
                    where l.locktype = 'relation'
                      and l.database = (select oid from pg_database where datname = current_database())
                      and l.relation = ?::oid
                      and l.pid is distinct from pg_backend_pid()
                      and l.virtualtransaction is not null
                    SQL,
                [$table->oid],
                false,
            )),
        );

        if ($lockers === []) {
            return;
        }

        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        $list = '{'.implode(',', array_map(static fn (string $vxid): string => '"'.addcslashes($vxid, '"\\').'"', $lockers)).'}';

        while ($this->stillLocked($table, $list)) {
            if (hrtime(true) >= $deadline) {
                throw new LockWaitExpired(sprintf(
                    'Transactions %s still hold or wait for locks on "%s" after %d ms.',
                    implode(', ', $lockers),
                    $table->table->name,
                    $timeoutMs,
                ));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The schema of the table's sequence, checked.
     *
     * @throws UnmanageableTable when the sequence is missing, can hand out an id below 0 or count
     *                           down, or the connection's role may not read it
     */
    private function sequenceSchema(SequencePartitionedTable $table): string
    {
        $rows = CatalogRow::all($this->connection->select(
            <<<'SQL'
                select n.nspname::text as schema,
                       s.seqincrement::bigint as increment,
                       s.seqmin::bigint as minimum,
                       has_sequence_privilege(s.seqrelid, 'SELECT') as readable,
                       current_user::text as role
                from pg_sequence s
                join pg_class c on c.oid = s.seqrelid
                join pg_namespace n on n.oid = c.relnamespace
                where s.seqrelid = to_regclass(?)
                SQL,
            [Sql::identifier($table->sequence)],
            false,
        ));

        if ($rows === []) {
            throw UnmanageableTable::sequenceMissing($table->name, $table->sequence, $this->connectionName());
        }

        $row = $rows[0];
        $increment = $row->int('increment');
        $minimum = $row->int('minimum');

        if ($increment < 1 || $minimum < 0) {
            throw UnmanageableTable::sequenceNotAscending($table->name, $table->sequence, $increment, $minimum);
        }

        if (! $row->bool('readable')) {
            throw UnmanageableTable::sequenceUnreadable($table->name, $table->sequence, $row->string('role'));
        }

        return $row->string('schema');
    }

    /**
     * @throws UnmanageableTable when the retention column is not a timestamptz column of the table
     */
    private function checkRetentionColumn(int $oid, string $table, SequenceRetention $retention): void
    {
        $usable = CatalogRow::one($this->connection->select(
            <<<'SQL'
                select exists (
                    select 1 from pg_attribute
                    where attrelid = ?::oid
                      and attname = ?
                      and attnum > 0
                      and not attisdropped
                      and atttypid = 'timestamptz'::regtype
                ) as usable
                SQL,
            [$oid, $retention->column],
            false,
        ))->bool('usable');

        if (! $usable) {
            throw UnmanageableTable::retentionColumn($table, $retention->column);
        }
    }

    /**
     * The connection's name in config/database.php. A connection made without the database
     * manager has none.
     */
    private function connectionName(): string
    {
        return $this->connection->getName() ?? 'without a name';
    }

    private function stillLocked(CatalogTable $table, string $lockers): bool
    {
        return CatalogRow::one($this->connection->select(
            <<<'SQL'
                select exists (
                    select 1 from pg_locks l
                    where l.locktype = 'relation'
                      and l.database = (select oid from pg_database where datname = current_database())
                      and l.relation = ?::oid
                      and l.virtualtransaction = any (?::text[])
                ) as locked
                SQL,
            [$table->oid, $lockers],
            false,
        ))->bool('locked');
    }
}

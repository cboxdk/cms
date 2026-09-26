<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Illuminate\Database\Connection;

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
     * by range, and it has no DEFAULT partition.
     */
    public function table(PartitionedTable $table): CatalogTable
    {
        $rows = CatalogRow::all($this->connection->select(
            <<<'SQL'
                select c.oid::bigint as oid,
                       n.nspname::text as schema,
                       c.relkind::text as kind,
                       c.relrowsecurity as row_security,
                       c.relforcerowsecurity as force_row_security,
                       pt.partstrat::text as strategy,
                       d.relname::text as default_partition
                from pg_class c
                join pg_namespace n on n.oid = c.relnamespace
                left join pg_partitioned_table pt on pt.partrelid = c.oid
                left join pg_class d on d.oid = pt.partdefid
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

        return new CatalogTable(
            table: $table,
            oid: $row->int('oid'),
            schema: $row->string('schema'),
            rowSecurity: $row->bool('row_security'),
            forceRowSecurity: $row->bool('force_row_security'),
        );
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

            if (! $partition instanceof Partition) {
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

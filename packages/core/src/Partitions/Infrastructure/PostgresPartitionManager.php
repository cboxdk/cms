<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * Keeps range partitions on Postgres, as the owner role (PRD 4, 4.1, 4.2).
 *
 * Creating a partition takes no heavy lock on the parent: the partition is created as a table of
 * its own (LIKE the parent) and attached, in one transaction, and ATTACH PARTITION takes SHARE
 * UPDATE EXCLUSIVE on the parent where CREATE TABLE ... PARTITION OF takes ACCESS EXCLUSIVE. The
 * new partition gets the parent's row security flags and the parent's grants, so reading or
 * writing it directly is not a way around the parent's policies or privileges. Without the copy,
 * the owner's default privileges would give the app role DELETE on every new partition.
 *
 * Removing a partition past retention is ALTER TABLE ... DETACH PARTITION ... CONCURRENTLY,
 * outside a transaction, then DROP TABLE. Rows are never deleted. Before the detach the manager
 * waits for the transactions that use the parent, within the lock timeout, so a busy parent makes
 * it give up before the detach starts rather than halfway. A detach that was interrupted halfway
 * anyway is finalized, and a detached table that was not dropped is dropped, on the next run.
 *
 * Every step runs under LockedDdl: lock_timeout and a bounded retry with backoff. One run at a
 * time holds a session advisory lock on the owner connection.
 */
#[Internal]
final readonly class PostgresPartitionManager implements PartitionMaintenance
{
    /** The advisory lock key of partition maintenance: crc32('cbox_cms.partition_maintenance'). */
    public const int ADVISORY_LOCK = 1_356_441_237;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private PartitionPolicy $policy,
    ) {}

    public function maintain(DateTimeImmutable $now): PartitionReport
    {
        $until = $now->modify(sprintf('+%d days', $this->policy->runwayDays));

        return $this->run(function (Run $run, CatalogTable $table) use ($now, $until): void {
            $this->create($run, $table, $table->table->partitionsCovering($now, $until));
            $this->retire($run, $table, $now);
        });
    }

    public function cover(DateTimeImmutable $from, DateTimeImmutable $to): PartitionReport
    {
        foreach ($this->policy->tables as $table) {
            $table->partitionsCovering($from, $to);
        }

        return $this->run(function (Run $run, CatalogTable $table) use ($from, $to): void {
            $this->create($run, $table, $table->table->partitionsCovering($from, $to));
        });
    }

    /**
     * @param  Closure(Run, CatalogTable): void  $work
     */
    private function run(Closure $work): PartitionReport
    {
        $connection = $this->ownerConnection();
        $catalog = new PartitionCatalog($connection);
        $role = $catalog->role();

        if (! $catalog->canCreateTables()) {
            throw OwnerConnectionRequired::withoutDdl($this->policy->ownerConnection, $role);
        }

        $tables = array_map($catalog->table(...), $this->policy->tables);
        $run = new Run($connection, $catalog, new LockedDdl($connection, $this->policy));

        $run->ddl->run(DdlStep::Lock, null, null, static function () use ($connection): void {
            $connection->select('select pg_advisory_lock(?)', [self::ADVISORY_LOCK], false);
        });

        try {
            foreach ($tables as $table) {
                $work($run, $table);
            }
        } finally {
            $connection->select('select pg_advisory_unlock(?)', [self::ADVISORY_LOCK], false);
        }

        return new PartitionReport(
            role: $role,
            changes: $run->changes(),
            runways: array_map(fn (CatalogTable $table): TableRunway => $this->runway($catalog, $table), $tables),
        );
    }

    private function ownerConnection(): Connection
    {
        $name = $this->policy->ownerConnection;

        if ($name === $this->connections->getDefaultConnection()) {
            throw OwnerConnectionRequired::appConnection($name);
        }

        $connection = $this->connections->connection($name);

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
            throw new LogicException(sprintf('The owner connection [%s] is not a Postgres connection.', $name));
        }

        if ($connection->transactionLevel() > 0) {
            throw UnmanageableTable::inTransaction($name);
        }

        return $connection;
    }

    /**
     * @param  list<Partition>  $wanted
     */
    private function create(Run $run, CatalogTable $table, array $wanted): void
    {
        $existing = array_map(
            static fn (CatalogPartition $found): string => $found->partition->name,
            $run->catalog->partitions($table),
        );

        foreach ($wanted as $partition) {
            if (in_array($partition->name, $existing, true)) {
                continue;
            }

            $run->ddl->run(DdlStep::Create, $table->table->name, $partition->name, function () use ($run, $table, $partition): void {
                $this->createPartition($run->connection, $table, $partition);
            });

            $run->record($table, $partition, PartitionChangeKind::Created);
        }
    }

    private function createPartition(Connection $connection, CatalogTable $table, Partition $partition): void
    {
        $name = $table->qualifiedPartition($partition->name);

        $connection->beginTransaction();

        $connection->statement(sprintf(
            'create table %s (like %s including defaults including constraints including generated including storage including compression)',
            $name,
            $table->qualifiedName(),
        ));

        if ($table->rowSecurity) {
            $connection->statement(sprintf('alter table %s enable row level security', $name));
        }

        if ($table->forceRowSecurity) {
            $connection->statement(sprintf('alter table %s force row level security', $name));
        }

        new TablePrivileges($connection)->copy($table->qualifiedName(), $name);

        $connection->statement(sprintf(
            'alter table %s attach partition %s for values from (%s) to (%s)',
            $table->qualifiedName(),
            $name,
            Sql::literal($partition->from()),
            Sql::literal($partition->to()),
        ));

        $connection->commit();
    }

    private function retire(Run $run, CatalogTable $table, DateTimeImmutable $now): void
    {
        foreach ($run->catalog->partitions($table) as $found) {
            $partition = $found->partition;

            if (! $partition->isExpiredAt($now)) {
                continue;
            }

            if ($found->state !== PartitionState::Detached) {
                $this->detach($run, $table, $partition);
            }

            $run->ddl->run(DdlStep::Drop, $table->table->name, $partition->name, static function () use ($run, $table, $partition): void {
                $run->connection->statement(sprintf('drop table %s', $table->qualifiedPartition($partition->name)));
            });

            $run->record($table, $partition, PartitionChangeKind::Dropped);
        }
    }

    /**
     * Detaches the partition, or finalizes a detach that stopped halfway. The state is read again
     * on every attempt, because an attempt can stop after the detach's first phase.
     */
    private function detach(Run $run, CatalogTable $table, Partition $partition): void
    {
        $done = PartitionChangeKind::Detached;

        $run->ddl->run(DdlStep::Detach, $table->table->name, $partition->name, function () use ($run, $table, $partition, &$done): void {
            $state = $run->catalog->state($table, $partition->name);

            if ($state === PartitionState::Attached) {
                $run->catalog->waitForLockers($table, $this->policy->lockTimeoutMs);
                $run->connection->statement(sprintf(
                    'alter table %s detach partition %s concurrently',
                    $table->qualifiedName(),
                    $table->qualifiedPartition($partition->name),
                ));
                $done = PartitionChangeKind::Detached;

                return;
            }

            if ($state === PartitionState::DetachPending) {
                $run->connection->statement(sprintf(
                    'alter table %s detach partition %s finalize',
                    $table->qualifiedName(),
                    $table->qualifiedPartition($partition->name),
                ));
                $done = PartitionChangeKind::Finalized;
            }
        });

        $run->record($table, $partition, $done);
    }

    private function runway(PartitionCatalog $catalog, CatalogTable $table): TableRunway
    {
        $ends = array_map(
            static fn (CatalogPartition $found): DateTimeImmutable => $found->partition->end,
            array_filter($catalog->partitions($table), static fn (CatalogPartition $found): bool => $found->state === PartitionState::Attached),
        );

        return new TableRunway($table->table->name, $ends === [] ? null : max($ends));
    }
}

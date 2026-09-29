<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions\Fakes;

use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\FailedTable;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Core\Partitions\Domain\SequencePartition;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequenceRetention;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Closure;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * Partition maintenance in memory, with the policy's tables and spans (PRD 4, 4.2).
 *
 * It keeps the attached partitions of each table, creates the runway and the ranges it is asked
 * for, and detaches and drops the partitions past retention, and it reports the changes as the
 * Postgres manager does. A test scripts the locks the manager can meet: holdRunLock() is another
 * run holding the maintenance lock, so the next run gives up at the lock step; lockTable() is a
 * session holding a lock on a table, so the next step that creates or detaches a partition of it
 * gives up. Both give up with LockTimeout after the policy's attempts and change nothing in that
 * step, as the real manager does: the run lock is thrown, a table's lock is in the report's
 * gaveUp as a GaveUpStep while the run goes on with the other tables. A maintain run ends by
 * analyzing each table whose partitions it changed, in policy order, and names them in the report's
 * analyzed; each managed table is the root of its own tree here, and a locked table gives up at the
 * analyze step as well. dropTable() is a table of the policy that is not in the database, such as
 * one whose migration has not run: the run records it in the report's failed as a FailedTable,
 * gives it no phase and no runway, and goes on with the other tables. A policy on the
 * application's connection is refused with OwnerConnectionRequired.
 *
 * A table partitioned on a sequence gets its runway ahead of the sequence's current value, which
 * advanceSequence() sets; a sequence starts at 0, as a new Postgres sequence that has handed out
 * nothing reads. insertRow() is a row with an id and a time in the retention column, so the
 * retirement of a partition the sequence has passed reads its newest row, in id order up to the
 * first partition kept. The roots the constructor takes name the root of the partition tree of
 * each leaf parent below a LIST level, which a run analyzes once; any other table is its own root.
 *
 * PartitionMaintenanceBehaviour holds it to PostgresPartitionManager.
 *
 * It does not model a detach that an earlier run left pending, or a table that exists but that
 * Postgres cannot manage: one partitioned by list or with a DEFAULT partition, or a detached table
 * with a managed name that does not fit its span.
 */
final class FakePartitionMaintenance implements PartitionMaintenance
{
    /** @var array<string, array<string, Partition|SequencePartition>> the attached partitions, by table and then partition name */
    private array $attached = [];

    /** @var array<string, int> the current value of each sequence that advanceSequence() set, by name */
    private array $sequences = [];

    /** @var array<string, array<string, DateTimeImmutable>> the newest row of each partition with rows, by table and then partition name */
    private array $newest = [];

    private bool $runLockHeld = false;

    /** @var array<string, true> */
    private array $lockedTables = [];

    /** @var array<string, true> */
    private array $droppedTables = [];

    /** @var list<PartitionChange> */
    private array $changes = [];

    /**
     * @param  string  $appConnection  the application's default connection, which the policy may not name
     * @param  string  $role  the role the report says ran the DDL
     * @param  array<string, string>  $roots  the root of the partition tree of each managed table below a LIST level, by table
     */
    public function __construct(
        private readonly PartitionPolicy $policy,
        private readonly string $appConnection = 'pgsql',
        private readonly string $role = 'cms_owner',
        private readonly array $roots = [],
    ) {}

    #[Override]
    public function maintain(DateTimeImmutable $now): PartitionReport
    {
        $until = $now->modify(sprintf('+%d days', $this->policy->runwayDays));

        return $this->run(
            $now,
            true,
            function (PartitionedTable|SequencePartitionedTable $table) use ($now, $until): void {
                $this->create($table, $table instanceof PartitionedTable ? $table->partitionsCovering($now, $until) : $this->sequenceRunway($table));
            },
            function (PartitionedTable|SequencePartitionedTable $table) use ($now): void {
                if ($table instanceof PartitionedTable) {
                    $this->retire($table, $now);
                } elseif ($table->retention instanceof SequenceRetention) {
                    $this->retireSequence($table, $now);
                }
            },
        );
    }

    #[Override]
    public function cover(PartitionRange $range, DateTimeImmutable $now): PartitionReport
    {
        foreach ($this->policy->tables as $table) {
            $table->partitionsCovering($range->from, $range->to);
        }

        return $this->run($now, false, function (PartitionedTable|SequencePartitionedTable $table) use ($range): void {
            $this->create($table, $table instanceof PartitionedTable ? $table->partitionsCovering($range->from, $range->to) : $this->sequenceRunway($table));
        });
    }

    /**
     * Another run holds the maintenance lock until releaseRunLock().
     */
    public function holdRunLock(): void
    {
        $this->runLockHeld = true;
    }

    public function releaseRunLock(): void
    {
        $this->runLockHeld = false;
    }

    /**
     * A session holds a lock on the table until unlockTable(), so creating or detaching one of its
     * partitions gives up with LockTimeout.
     */
    public function lockTable(string $table): void
    {
        $this->lockedTables[$table] = true;
    }

    public function unlockTable(string $table): void
    {
        unset($this->lockedTables[$table]);
    }

    /**
     * The sequence's current value is now $current, as setval() leaves it.
     */
    public function advanceSequence(string $sequence, int $current): void
    {
        $this->sequences[$sequence] = $current;
    }

    /**
     * A row with the id and the time in the retention column, in the attached partition of the
     * table partitioned on a sequence that holds the id.
     */
    public function insertRow(string $table, int $id, DateTimeImmutable $at): void
    {
        $sequenced = array_find($this->policy->sequenceTables, static fn (SequencePartitionedTable $candidate): bool => $candidate->name === $table)
            ?? throw new LogicException(sprintf('The table "%s" is not partitioned on a sequence in the policy.', $table));
        $name = $sequenced->partitionHolding($id)->name;

        if (! isset($this->attached[$table][$name])) {
            throw new LogicException(sprintf('No partition of "%s" holds the id %d.', $table, $id));
        }

        $newest = $this->newest[$table][$name] ?? null;
        $this->newest[$table][$name] = $newest instanceof DateTimeImmutable && $newest > $at ? $newest : $at;
    }

    /**
     * The table and its partitions are no longer in the database.
     */
    public function dropTable(string $table): void
    {
        $this->droppedTables[$table] = true;
        unset($this->attached[$table]);
    }

    /**
     * The attached partitions of a table, by name.
     *
     * @return list<string>
     */
    public function partitions(string $table): array
    {
        $names = array_keys($this->attached[$table] ?? []);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Runs each phase over every table before the next phase, and records a table that gives up
     * or is missing instead of stopping the run, as the Postgres manager does.
     *
     * @param  bool  $analyze  whether the run ends by analyzing the tables it changed
     * @param  Closure(PartitionedTable|SequencePartitionedTable): void  ...$phases
     */
    private function run(DateTimeImmutable $now, bool $analyze, Closure ...$phases): PartitionReport
    {
        if ($this->policy->ownerConnection === $this->appConnection) {
            throw OwnerConnectionRequired::appConnection($this->policy->ownerConnection);
        }

        if ($this->runLockHeld) {
            throw $this->gaveUp(DdlStep::Lock, null, null);
        }

        $this->changes = [];
        $gaveUp = [];
        $failed = [];
        $tables = [];

        foreach ([...$this->policy->tables, ...$this->policy->sequenceTables] as $table) {
            if (isset($this->droppedTables[$table->name])) {
                $failed[] = FailedTable::of($table->name, UnmanageableTable::missing($table->name, $this->policy->ownerConnection));

                continue;
            }

            $tables[] = $table;
        }

        foreach ($phases as $phase) {
            foreach ($tables as $table) {
                try {
                    $phase($table);
                } catch (LockTimeout $timeout) {
                    $gaveUp[] = GaveUpStep::of($timeout);
                }
            }
        }

        $analyzed = [];

        foreach ($analyze ? $tables : [] as $table) {
            $root = $this->roots[$table->name] ?? $table->name;

            if (in_array($root, $analyzed, true) || ! $this->changed($table)) {
                continue;
            }

            if (isset($this->lockedTables[$table->name])) {
                $gaveUp[] = GaveUpStep::of($this->gaveUp(DdlStep::Analyze, $root, null));

                continue;
            }

            $analyzed[] = $root;
        }

        return new PartitionReport(
            role: $this->role,
            changes: $this->changes,
            runways: array_map(fn (PartitionedTable|SequencePartitionedTable $table): TableRunway => $this->runway($table, $now), $tables),
            gaveUp: $gaveUp,
            failed: $failed,
            analyzed: $analyzed,
        );
    }

    private function changed(PartitionedTable|SequencePartitionedTable $table): bool
    {
        return array_any($this->changes, fn (PartitionChange $change): bool => $change->table === $table->name);
    }

    /**
     * @param  list<Partition>|list<SequencePartition>  $wanted
     */
    private function create(PartitionedTable|SequencePartitionedTable $table, array $wanted): void
    {
        foreach ($wanted as $partition) {
            if (isset($this->attached[$table->name][$partition->name])) {
                continue;
            }

            if (isset($this->lockedTables[$table->name])) {
                throw $this->gaveUp(DdlStep::Create, $table->name, $partition->name);
            }

            $this->attached[$table->name][$partition->name] = $partition;
            $this->changes[] = new PartitionChange($table->name, $partition->name, PartitionChangeKind::Created);
        }
    }

    private function retire(PartitionedTable $table, DateTimeImmutable $now): void
    {
        foreach ($this->partitions($table->name) as $name) {
            $partition = $this->attached[$table->name][$name];

            if (! $partition instanceof Partition || ! $partition->isExpiredAt($now)) {
                continue;
            }

            if (isset($this->lockedTables[$table->name])) {
                throw $this->gaveUp(DdlStep::Detach, $table->name, $name);
            }

            unset($this->attached[$table->name][$name]);
            $this->changes[] = new PartitionChange($table->name, $name, PartitionChangeKind::Detached);
            $this->changes[] = new PartitionChange($table->name, $name, PartitionChangeKind::Dropped);
        }
    }

    /**
     * The partition that holds the sequence's current value and the runway's empty ones after it.
     *
     * @return list<SequencePartition>
     */
    private function sequenceRunway(SequencePartitionedTable $table): array
    {
        return $table->runway($this->sequences[$table->sequence] ?? 0, $this->policy->runwayPartitions);
    }

    /**
     * Detaches and drops, in id order, each partition the sequence has passed whose newest row is
     * past retention, up to the first partition it keeps.
     */
    private function retireSequence(SequencePartitionedTable $table, DateTimeImmutable $now): void
    {
        $current = $this->sequences[$table->sequence] ?? 0;

        foreach ($this->partitions($table->name) as $name) {
            $partition = $this->attached[$table->name][$name];

            if (! $partition instanceof SequencePartition || ! $partition->isExpiredAt($current, $this->newest[$table->name][$name] ?? null, $now)) {
                return;
            }

            if (isset($this->lockedTables[$table->name])) {
                throw $this->gaveUp(DdlStep::Detach, $table->name, $name);
            }

            unset($this->attached[$table->name][$name], $this->newest[$table->name][$name]);
            $this->changes[] = new PartitionChange($table->name, $name, PartitionChangeKind::Detached);
            $this->changes[] = new PartitionChange($table->name, $name, PartitionChangeKind::Dropped);
        }
    }

    private function runway(PartitionedTable|SequencePartitionedTable $table, DateTimeImmutable $now): TableRunway
    {
        $attached = array_values($this->attached[$table->name] ?? []);

        if ($table instanceof SequencePartitionedTable) {
            return new TableRunway($table->name, null, PartitionRunway::ahead($table, $attached, $this->sequences[$table->sequence] ?? 0));
        }

        return new TableRunway($table->name, PartitionRunway::end($table, $attached, $now));
    }

    private function gaveUp(DdlStep $step, ?string $table, ?string $partition): LockTimeout
    {
        return LockTimeout::gaveUp($step, $table, $partition, $this->policy->attempts, $this->policy->lockTimeoutSetting());
    }
}

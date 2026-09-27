<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;

/**
 * What one run of partition maintenance did, and as which database role.
 *
 * A table whose lock stayed busy does not stop the run: the step that gave up is in $gaveUp, and
 * the run went on with the other tables and the next phase. Nor does a table the run cannot
 * manage: it is in $failed, and the run went on without it. The run is complete when nothing
 * gave up and no table failed. The report holds values only; the LockTimeout of each step that
 * gave up and the UnmanageableTable of each table that failed are not kept.
 */
#[Experimental]
final readonly class PartitionReport
{
    /**
     * @param  string  $role  the database role that ran the DDL
     * @param  list<PartitionChange>  $changes  in the order they were made
     * @param  list<TableRunway>  $runways  one per managed table the run could read, in policy
     *                                      order: a table in $failed with no partition could not
     *                                      be read and has none
     * @param  list<GaveUpStep>  $gaveUp  the steps that gave up on a busy lock, in the order they
     *                                    were tried: at most one per table and phase
     * @param  list<FailedTable>  $failed  the tables the run could not manage, in the order it
     *                                     found them: at most one per table
     */
    public function __construct(
        public string $role,
        public array $changes,
        public array $runways,
        public array $gaveUp,
        public array $failed,
    ) {}

    /**
     * Whether every table got every phase of the run: no step gave up on a lock and no table
     * failed.
     */
    public function isComplete(): bool
    {
        return $this->gaveUp === [] && $this->failed === [];
    }

    /**
     * The names of the partitions that had the given change, in order.
     *
     * @return list<string>
     */
    public function partitions(PartitionChangeKind $kind): array
    {
        return array_values(array_map(
            static fn (PartitionChange $change): string => $change->partition,
            array_filter($this->changes, static fn (PartitionChange $change): bool => $change->kind === $kind),
        ));
    }
}

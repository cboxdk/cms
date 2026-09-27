<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;

/**
 * What one run of partition maintenance did, and as which database role.
 *
 * A table whose lock stayed busy does not stop the run: the step that gave up is in $gaveUp, and
 * the run went on with the other tables and the next phase. The run is complete when nothing
 * gave up.
 */
#[Experimental]
final readonly class PartitionReport
{
    /**
     * @param  string  $role  the database role that ran the DDL
     * @param  list<PartitionChange>  $changes  in the order they were made
     * @param  list<TableRunway>  $runways  one per managed table, in policy order
     * @param  list<LockTimeout>  $gaveUp  the steps that gave up on a busy lock, in the order they
     *                                     were tried: at most one per table and phase
     */
    public function __construct(
        public string $role,
        public array $changes,
        public array $runways,
        public array $gaveUp,
    ) {}

    /**
     * Whether every table got every phase of the run, so no step gave up on a lock.
     */
    public function isComplete(): bool
    {
        return $this->gaveUp === [];
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

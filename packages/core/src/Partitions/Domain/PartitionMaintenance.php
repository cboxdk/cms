<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use DateTimeImmutable;

/**
 * Keeps the partitions of the tables in the partition policy (PRD 4, 4.2).
 *
 * Only the owner role's connection runs it; on any other connection it throws
 * OwnerConnectionRequired before it changes anything. When another run holds the maintenance
 * lock within the lock timeout, on every attempt, it throws LockTimeout before it changes
 * anything. A step on one table that cannot get its lock within the lock timeout, on every
 * attempt, ends that phase for that table only: the report's gaveUp holds it as a GaveUpStep,
 * and the run goes on with the other tables.
 */
#[Internal]
interface PartitionMaintenance
{
    /**
     * Creates the partitions from the span that holds $now to the runway's end for every table,
     * then removes the partitions past retention with DETACH PARTITION CONCURRENTLY and DROP
     * TABLE. The report measures each table's runway from $now with PartitionRunway.
     *
     * @throws LockTimeout when another run holds the maintenance lock
     */
    public function maintain(DateTimeImmutable $now): PartitionReport;

    /**
     * Creates the partitions whose spans overlap the range, and removes nothing. For rows that
     * arrive with past or future keys, and for tests at any date. The report measures each
     * table's runway from $now with PartitionRunway, so a range after a gap does not extend it.
     *
     * @throws LockTimeout when another run holds the maintenance lock
     * @throws InvalidPartitionPolicy when the range needs more than
     *                                PartitionedTable::MAX_PARTITIONS_PER_CALL partitions of a table;
     *                                nothing is created then
     */
    public function cover(PartitionRange $range, DateTimeImmutable $now): PartitionReport;
}

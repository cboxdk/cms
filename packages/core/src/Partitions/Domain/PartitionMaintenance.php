<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use DateTimeImmutable;

/**
 * Keeps the partitions of the tables in the partition policy (PRD 4, 4.2).
 *
 * Only the owner role's connection runs it; on any other connection it throws
 * OwnerConnectionRequired before it changes anything. A step that cannot get its lock within the
 * lock timeout, on every attempt, throws LockTimeout.
 */
#[Experimental]
interface PartitionMaintenance
{
    /**
     * Creates the partitions from the span that holds $now to the runway's end, and removes the
     * partitions past retention with DETACH PARTITION CONCURRENTLY and DROP TABLE.
     */
    public function maintain(DateTimeImmutable $now): PartitionReport;

    /**
     * Creates the partitions whose spans overlap [$from, $to], and removes nothing. For rows that
     * arrive with past or future keys, and for tests at any date.
     */
    public function cover(DateTimeImmutable $from, DateTimeImmutable $to): PartitionReport;
}

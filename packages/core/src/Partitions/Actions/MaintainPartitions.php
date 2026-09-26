<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;

/**
 * Partition maintenance as the surfaces call it: `cms:partitions:maintain` and the schedule.
 *
 * The time comes from the Clock, so a test with a FakeClock maintains partitions at its date.
 */
#[Experimental]
final readonly class MaintainPartitions
{
    public function __construct(
        private PartitionMaintenance $partitions,
        private Clock $clock,
    ) {}

    /**
     * Creates the runway ahead of the Clock and removes the partitions past retention.
     */
    public function maintain(): PartitionReport
    {
        return $this->partitions->maintain($this->clock->now());
    }

    /**
     * Creates the partitions that cover the range and removes nothing. The report's runway is
     * measured from the Clock's time, whatever the range.
     */
    public function cover(PartitionRange $range): PartitionReport
    {
        return $this->partitions->cover($range, $this->clock->now());
    }
}

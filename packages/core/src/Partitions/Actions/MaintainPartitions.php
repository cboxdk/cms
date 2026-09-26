<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use DateTimeImmutable;

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
     * Creates the partitions that cover [$from, $to] and removes nothing.
     */
    public function cover(DateTimeImmutable $from, DateTimeImmutable $to): PartitionReport
    {
        return $this->partitions->cover($from, $to);
    }
}

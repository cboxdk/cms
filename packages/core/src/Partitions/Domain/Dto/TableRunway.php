<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * How far writes to a table can go after a run, measured with PartitionRunway.
 *
 * For a table partitioned on time, $coveredUntil is the exclusive end of the unbroken run of
 * attached partitions that starts with the partition holding the run's time, or null when no
 * partition holds it. A write keyed at or after it can fail with PartitionMissing, because a gap
 * ends the run. For a table partitioned on a sequence, $coveredUntil is null and $sequence holds
 * the runway in ids and in empty partitions ahead of the sequence's current value.
 */
#[Experimental]
final readonly class TableRunway
{
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
        public ?SequenceRunway $sequence = null,
    ) {}
}

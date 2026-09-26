<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * How far writes to a table can go after a run, measured from the run's time with
 * PartitionRunway: the exclusive end of the unbroken run of attached partitions that starts with
 * the partition holding that time, or null when no partition holds it. A write keyed at or after
 * it can fail with PartitionMissing, because a gap ends the run.
 */
#[Experimental]
final readonly class TableRunway
{
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
    ) {}
}

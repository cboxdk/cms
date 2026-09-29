<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How far the ids of a table partitioned on a sequence can go (PRD 4, 4.2), measured with
 * PartitionRunway::ahead(): the unbroken run of attached partitions from the one that holds the
 * sequence's current value. A gap ends the run, because a write in the gap fails.
 */
#[Experimental]
final readonly class SequenceRunway
{
    /**
     * @param  int  $current  the sequence's current value: the last id it handed out, or -1 before its first
     * @param  int|null  $coveredUntil  the exclusive upper bound of the run's last partition, or null when no attached partition holds the current value
     * @param  int  $partitionsAhead  how many partitions of the run are ahead of the current value, and so empty; 0 without a run
     */
    public function __construct(
        public int $current,
        public ?int $coveredUntil,
        public int $partitionsAhead,
    ) {}
}

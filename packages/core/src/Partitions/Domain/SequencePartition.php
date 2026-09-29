<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * One partition of a table partitioned on a sequence: the ids from $lower, inclusive, to $upper,
 * exclusive.
 */
#[Experimental]
final readonly class SequencePartition
{
    public function __construct(
        public SequencePartitionedTable $table,
        public string $name,
        public int $lower,
        public int $upper,
    ) {}

    /** The lower bound, inclusive, as the text of a Postgres literal without quotes. */
    public function from(): string
    {
        return (string) $this->lower;
    }

    /** The upper bound, exclusive, as the text of a Postgres literal without quotes. */
    public function to(): string
    {
        return (string) $this->upper;
    }

    /**
     * Whether the partition is ahead of the sequence's current value: it holds only ids the
     * sequence has not handed out, so it is empty.
     */
    public function isAhead(int $current): bool
    {
        return $this->lower > $current;
    }

    /**
     * Whether the sequence has passed the partition: every id it can hold is below the current
     * value, so the sequence hands out no more ids in it.
     */
    public function isPassed(int $current): bool
    {
        return $this->upper <= $current;
    }

    /**
     * Whether the partition is past the table's retention: the sequence has passed it, and its
     * newest row is at least the retention's days before $now. A passed partition without rows
     * has nothing to keep. A table without retention keeps every partition.
     *
     * @param  DateTimeImmutable|null  $newestRow  the newest value of the retention column in the partition, or null when it has no rows
     */
    public function isExpiredAt(int $current, ?DateTimeImmutable $newestRow, DateTimeImmutable $now): bool
    {
        $retention = $this->table->retention;

        if (! $retention instanceof SequenceRetention || ! $this->isPassed($current)) {
            return false;
        }

        return ! $newestRow instanceof DateTimeImmutable || $newestRow->modify(sprintf('+%d days', $retention->days)) <= $now;
    }
}

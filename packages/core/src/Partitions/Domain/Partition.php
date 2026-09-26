<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * One partition of a managed table: the span from $start, inclusive, to $end, exclusive.
 */
#[Experimental]
final readonly class Partition
{
    public function __construct(
        public PartitionedTable $table,
        public string $name,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    /** The lower bound, inclusive, as the text of a Postgres literal without quotes. */
    public function from(): string
    {
        return $this->table->key->bound($this->start);
    }

    /** The upper bound, exclusive, as the text of a Postgres literal without quotes. */
    public function to(): string
    {
        return $this->table->key->bound($this->end);
    }

    /**
     * Whether every row the partition can hold is past the table's retention at $now: the end of
     * the span plus the retention is not later than $now. A table without retention keeps all.
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        $days = $this->table->retentionDays;

        return $days !== null && $this->end->modify(sprintf('+%d days', $days)) <= $now;
    }
}

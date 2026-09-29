<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How long the rows of a table partitioned on a sequence are kept (PRD 4.2: events are dropped as
 * whole partitions after retention).
 *
 * An id says nothing about time, so the retention is read from a timestamptz column of the
 * table: a partition is past retention when the newest value of the column in it is at least
 * $days days before now.
 */
#[Experimental]
final readonly class SequenceRetention
{
    /**
     * @param  string  $table  the table whose retention it is, for the messages of its errors
     * @param  int  $days  how many days after its newest row a partition is kept
     * @param  string  $column  the timestamptz column the age of a row is read from, unquoted
     */
    public function __construct(
        public string $table,
        public int $days,
        public string $column,
    ) {
        if ($days < 1) {
            throw InvalidPartitionPolicy::retention($table, $days);
        }

        if (preg_match(PartitionedTable::NAME_PATTERN, $column) !== 1 || strlen($column) > PartitionedTable::MAX_IDENTIFIER_LENGTH) {
            throw InvalidPartitionPolicy::retentionColumn($table, $column);
        }
    }
}

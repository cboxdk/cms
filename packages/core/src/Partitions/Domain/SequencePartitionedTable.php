<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A table range-partitioned on a bigint key that a sequence feeds, which the partition manager
 * keeps (PRD 4, 4.1, 4.2, 7.2): `revision_payloads` on `revision_id`, below its LIST level on
 * `kind`, and `events` on `event_id`.
 *
 * Every partition holds $width ids, from a multiple of $width. It is named `<table>_p<lower
 * bound>`, with the lower bound zero-padded to 19 digits, the length of the largest bigint:
 * `events_p0000000000001000000` holds the ids from 1000000 to 1999999 when the width is 1000000.
 * The manager only touches partitions with that name. The runway is counted in empty partitions
 * ahead of the sequence's current value, not in time, because a sequence's pace follows the
 * writes. The table has no DEFAULT partition, so a row outside the partitions fails loudly.
 *
 * Ids below 0 have no partition: the partition manager refuses a sequence that can hand one out.
 */
#[Experimental]
final readonly class SequencePartitionedTable
{
    /** The digits of the largest bigint, PHP_INT_MAX, and so of every partition's lower bound in its name. */
    public const int BOUND_DIGITS = 19;

    /**
     * @param  string  $name  the table's name, unquoted and without schema; it is resolved in the owner connection's search path
     * @param  int  $width  how many ids each partition holds
     * @param  string  $sequence  the sequence that feeds the key, unquoted and without schema; it is resolved in the search path
     * @param  SequenceRetention|null  $retention  when a partition the sequence has passed is removed; null keeps every partition
     */
    public function __construct(
        public string $name,
        public int $width,
        public string $sequence,
        public ?SequenceRetention $retention = null,
    ) {
        $longestName = PartitionedTable::MAX_IDENTIFIER_LENGTH - strlen('_p') - self::BOUND_DIGITS;

        if (preg_match(PartitionedTable::NAME_PATTERN, $name) !== 1 || strlen($name) > $longestName) {
            throw InvalidPartitionPolicy::tableName($name, $longestName);
        }

        if ($width < 1) {
            throw InvalidPartitionPolicy::width($name, $width);
        }

        if (preg_match(PartitionedTable::NAME_PATTERN, $sequence) !== 1 || strlen($sequence) > PartitionedTable::MAX_IDENTIFIER_LENGTH) {
            throw InvalidPartitionPolicy::sequenceName($name, $sequence);
        }
    }

    /**
     * The partition that holds the id.
     *
     * The last partition ends at the largest bigint, exclusive, so the id PHP_INT_MAX itself has
     * none; a sequence's default maximum is that value, and a table never gets that far.
     */
    public function partitionHolding(int $id): SequencePartition
    {
        if ($id < 0) {
            throw new InvalidArgumentException(sprintf('The id %d of table "%s" is below 0, and ids below 0 have no partition.', $id, $this->name));
        }

        $lower = intdiv($id, $this->width) * $this->width;
        $upper = $lower > PHP_INT_MAX - $this->width ? PHP_INT_MAX : $lower + $this->width;

        return new SequencePartition($this, $this->partitionName($lower), $lower, $upper);
    }

    /**
     * The partitions of the runway: from the one that holds the current value, or the first one
     * when the sequence has handed out nothing yet, until $ahead of them are ahead of it, in order.
     *
     * @param  int  $current  the sequence's current value: the last id it handed out, or -1 before its first
     * @param  int  $ahead  how many empty partitions the runway has ahead of the current value
     * @return list<SequencePartition>
     */
    public function runway(int $current, int $ahead): array
    {
        $partition = $this->partitionHolding(max($current, 0));
        $partitions = [$partition];
        $counted = $partition->isAhead($current) ? 1 : 0;

        while ($counted < $ahead && $partition->upper < PHP_INT_MAX) {
            $partition = $this->partitionHolding($partition->upper);
            $partitions[] = $partition;
            $counted++;
        }

        return $partitions;
    }

    /**
     * The partition a name stands for, or null when the name is not one of this table's: the
     * lower bound must be 19 digits, below PHP_INT_MAX, where the last partition ends, and a
     * multiple of the width.
     */
    public function partitionNamed(string $name): ?SequencePartition
    {
        $prefix = $this->name.'_p';

        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        $suffix = substr($name, strlen($prefix));

        if (preg_match('/\A\d{'.self::BOUND_DIGITS.'}\z/', $suffix) !== 1 || strcmp($suffix, (string) PHP_INT_MAX) >= 0) {
            return null;
        }

        $lower = (int) $suffix;

        return $lower % $this->width === 0 ? $this->partitionHolding($lower) : null;
    }

    private function partitionName(int $lower): string
    {
        return sprintf('%s_p%0'.self::BOUND_DIGITS.'d', $this->name, $lower);
    }
}

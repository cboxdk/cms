<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\Dto\SequenceRunway;
use DateTimeImmutable;

/**
 * How far writes to a managed table can go from an instant (PRD 4, 4.2).
 *
 * The runway is the exclusive end of the unbroken run of attached partitions that starts with the
 * partition holding the instant. A gap ends the run: the table has no DEFAULT partition, so a
 * write in the gap fails with PartitionMissing, and the partitions after it do not count. The
 * partition manager's report and the doctor's `partitions.runway` check both measure it here: with
 * end() from an instant for a table partitioned on time, and with ahead() from the sequence's
 * current value for a table partitioned on a sequence.
 */
#[Experimental]
final readonly class PartitionRunway
{
    /**
     * The end of the unbroken run of $attached partitions of $table from $now, or null when no
     * attached partition holds $now.
     *
     * @param  list<Partition|SequencePartition>  $attached  the table's attached partitions, in any order; others, such as a detached table with a managed name, do not belong here, and only the names count
     */
    public static function end(PartitionedTable $table, array $attached, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $names = [];

        foreach ($attached as $partition) {
            $names[$partition->name] = true;
        }

        $end = null;
        $partition = $table->partitionAt($now);

        // At most one step per attached partition, so the walk always ends.
        while (isset($names[$partition->name])) {
            unset($names[$partition->name]);
            $end = $partition->end;
            $partition = $table->partitionAt($partition->end);
        }

        return $end;
    }

    /**
     * The unbroken run of $attached partitions of $table from the one that holds the sequence's
     * current value, or from the first partition before the sequence's first id: its end and how
     * many of its partitions are ahead of the current value.
     *
     * @param  list<Partition|SequencePartition>  $attached  the table's attached partitions, in any order, of which only the names count
     * @param  int  $current  the sequence's current value: the last id it handed out, or -1 before its first
     */
    public static function ahead(SequencePartitionedTable $table, array $attached, int $current): SequenceRunway
    {
        $names = [];

        foreach ($attached as $partition) {
            $names[$partition->name] = true;
        }

        $end = null;
        $ahead = 0;
        $partition = $table->partitionHolding(max($current, 0));

        // At most one step per attached partition, so the walk always ends. After the last
        // partition, partitionHolding() of its upper bound, PHP_INT_MAX, is that partition again.
        while (isset($names[$partition->name])) {
            unset($names[$partition->name]);
            $end = $partition->upper;
            $ahead += $partition->isAhead($current) ? 1 : 0;
            $partition = $table->partitionHolding($partition->upper);
        }

        return new SequenceRunway($current, $end, $ahead);
    }
}

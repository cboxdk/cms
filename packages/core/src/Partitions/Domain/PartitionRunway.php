<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * How far writes to a managed table can go from an instant (PRD 4, 4.2).
 *
 * The runway is the exclusive end of the unbroken run of attached partitions that starts with the
 * partition holding the instant. A gap ends the run: the table has no DEFAULT partition, so a
 * write in the gap fails with PartitionMissing, and the partitions after it do not count. The
 * partition manager's report and the doctor's `partitions.runway` check both measure it here.
 */
#[Experimental]
final readonly class PartitionRunway
{
    /**
     * The end of the unbroken run of $attached partitions of $table from $now, or null when no
     * attached partition holds $now.
     *
     * @param  list<Partition>  $attached  the table's attached partitions, in any order; others, such as a detached table with a managed name, do not belong here
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
}

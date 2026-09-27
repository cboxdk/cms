<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Makes a date range that no partition covers, for the shared store suites' uncover() hook on
 * real Postgres (PRD 4, 4.2).
 *
 * PartitionFixtures only creates partitions, and they stay until the schema is rebuilt, so an
 * earlier test may have covered the range. open() drops, as the owner role, every partition of a
 * table in `cbox-cms.database.partitions.tables` whose span overlaps [$from, $to]: whole days or months,
 * so the gap can be wider than the range. A later test that needs those dates covers them again.
 */
final class PartitionGaps
{
    public static function open(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $policy = PartitionConfig::read(app(Repository::class));
        $owner = DB::connection($policy->ownerConnection);
        $owner->statement("set lock_timeout = '5s'");

        foreach ($policy->tables as $table) {
            foreach ($table->partitionsCovering($from, $to) as $partition) {
                $owner->statement(sprintf('drop table if exists "%s"', $partition->name));
            }
        }

        $owner->statement('reset lock_timeout');
    }
}

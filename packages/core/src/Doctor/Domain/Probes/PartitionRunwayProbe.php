<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use DateTimeImmutable;

/**
 * How far the partitions of each table in `cms.database.partitions.tables` reach from now without
 * a gap (PRD 4, 4.2), read from the catalog as the app role.
 */
#[Internal]
interface PartitionRunwayProbe
{
    /**
     * How far each managed table is covered from $now, in the configured order.
     *
     * @return list<PartitionCoverage>
     *
     * @throws ProbeFailed violation when a table is missing or cannot be managed, or the policy is invalid
     */
    public function coverage(DateTimeImmutable $now): array;
}

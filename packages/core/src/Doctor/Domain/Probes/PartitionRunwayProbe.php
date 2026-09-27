<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use DateTimeImmutable;

/**
 * How far the partitions of each table in `cbox-cms.database.partitions.tables` reach from now without
 * a gap (PRD 4, 4.2), read from the catalog as the app role.
 */
#[Internal]
interface PartitionRunwayProbe
{
    /**
     * How far each managed table is covered from $now, in the configured order. A table that is
     * missing or cannot be managed is in the list with the reason instead of a coverage.
     *
     * @return list<PartitionCoverage>
     *
     * @throws ProbeFailed a violation when the policy is invalid, and the kind of the Postgres error when the catalog cannot be read
     */
    public function coverage(DateTimeImmutable $now): array;
}

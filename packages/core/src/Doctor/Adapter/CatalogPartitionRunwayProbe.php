<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionState;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Override;
use Throwable;

/**
 * Reads the coverage of each managed table from the catalog on the doctor's connection, as the app
 * role, with the partition manager's own catalog queries, and measures it with PartitionRunway,
 * as the manager's report does: the unbroken run of attached partitions from now.
 */
#[Internal]
final readonly class CatalogPartitionRunwayProbe implements PartitionRunwayProbe
{
    public function __construct(
        private DoctorConnection $connection,
        private Repository $config,
    ) {}

    #[Override]
    public function coverage(DateTimeImmutable $now): array
    {
        try {
            $policy = PartitionConfig::read($this->config);
        } catch (InvalidPartitionPolicy $invalid) {
            throw ProbeFailed::violation($invalid->getMessage(), $invalid);
        }

        $catalog = new PartitionCatalog($this->connection->get());
        $coverage = [];

        foreach ($policy->tables as $table) {
            try {
                $attached = [];

                foreach ($catalog->partitions($catalog->table($table)) as $found) {
                    if ($found->state === PartitionState::Attached) {
                        $attached[] = $found->partition;
                    }
                }
            } catch (UnmanageableTable $unmanageable) {
                throw ProbeFailed::violation($unmanageable->getMessage(), $unmanageable);
            } catch (Throwable $thrown) {
                throw PostgresErrors::classify($thrown);
            }

            $coverage[] = new PartitionCoverage($table->name, PartitionRunway::end($table, $attached, $now));
        }

        return $coverage;
    }
}

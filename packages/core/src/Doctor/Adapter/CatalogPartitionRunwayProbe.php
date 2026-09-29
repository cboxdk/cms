<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Core\Partitions\Domain\SequencePartition;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Cbox\Cms\Core\Partitions\Infrastructure\CatalogTable;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionState;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Override;
use Throwable;

/**
 * Reads the coverage of each managed table from the catalog on the doctor's connection, as the app
 * role, with the partition manager's own catalog queries, and measures it with PartitionRunway,
 * as the manager's report does: the unbroken run of attached partitions from now, or from the
 * sequence's current value for a table partitioned on a sequence. A table it cannot manage is
 * reported with the reason, and the other tables are still read.
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
                $attached = $this->attached($catalog, $catalog->table($table));
            } catch (UnmanageableTable $unmanageable) {
                $coverage[] = PartitionCoverage::unmanageable($table->name, $unmanageable->getMessage());

                continue;
            } catch (Throwable $thrown) {
                throw PostgresErrors::classify($thrown);
            }

            $coverage[] = new PartitionCoverage($table->name, PartitionRunway::end($table, $attached, $now));
        }

        foreach ($policy->sequenceTables as $table) {
            try {
                $found = $catalog->table($table);
                $attached = $this->attached($catalog, $found);
                $current = $catalog->sequenceValue($found);
            } catch (UnmanageableTable $unmanageable) {
                $coverage[] = PartitionCoverage::unmanageable($table->name, $unmanageable->getMessage());

                continue;
            } catch (Throwable $thrown) {
                throw PostgresErrors::classify($thrown);
            }

            $coverage[] = PartitionCoverage::sequence($table->name, PartitionRunway::ahead($table, $attached, $current));
        }

        return $coverage;
    }

    /**
     * The attached partitions of a managed table.
     *
     * @return list<Partition|SequencePartition>
     */
    private function attached(PartitionCatalog $catalog, CatalogTable $table): array
    {
        $attached = [];

        foreach ($catalog->partitions($table) as $found) {
            if ($found->state === PartitionState::Attached) {
                $attached[] = $found->partition;
            }
        }

        return $attached;
    }
}

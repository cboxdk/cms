<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\FailedTable;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Illuminate\Database\Connection;

/**
 * One run of the partition manager: its connection, catalog, DDL runner, the changes so far, the
 * steps that gave up on a busy lock and the tables it could not manage.
 */
#[Internal]
final class Run
{
    /** @var list<PartitionChange> */
    private array $changes = [];

    /** @var list<GaveUpStep> */
    private array $gaveUp = [];

    /** @var list<FailedTable> */
    private array $failed = [];

    public function __construct(
        public readonly Connection $connection,
        public readonly PartitionCatalog $catalog,
        public readonly LockedDdl $ddl,
    ) {}

    public function record(CatalogTable $table, Partition $partition, PartitionChangeKind $kind): void
    {
        $this->changes[] = new PartitionChange($table->table->name, $partition->name, $kind);
    }

    /**
     * @return list<PartitionChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Records a step that gave up for the report, as values: the exception and its cause stay out.
     */
    public function gaveUp(LockTimeout $timeout): void
    {
        $this->gaveUp[] = GaveUpStep::of($timeout);
    }

    /**
     * @return list<GaveUpStep>
     */
    public function stepsGivenUp(): array
    {
        return $this->gaveUp;
    }

    /**
     * Records a table the run could not manage for the report, as values: the exception and its
     * cause stay out.
     */
    public function failed(string $table, UnmanageableTable $refusal): void
    {
        $this->failed[] = FailedTable::of($table, $refusal);
    }

    /**
     * @return list<FailedTable>
     */
    public function tablesFailed(): array
    {
        return $this->failed;
    }
}

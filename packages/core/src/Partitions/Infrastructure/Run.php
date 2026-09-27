<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Illuminate\Database\Connection;

/**
 * One run of the partition manager: its connection, catalog, DDL runner, the changes so far and
 * the steps that gave up on a busy lock.
 */
#[Internal]
final class Run
{
    /** @var list<PartitionChange> */
    private array $changes = [];

    /** @var list<GaveUpStep> */
    private array $gaveUp = [];

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
}

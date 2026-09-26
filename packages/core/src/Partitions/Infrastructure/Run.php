<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Illuminate\Database\Connection;

/**
 * One run of the partition manager: its connection, catalog, DDL runner and the changes so far.
 */
#[Internal]
final class Run
{
    /** @var list<PartitionChange> */
    private array $changes = [];

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
}

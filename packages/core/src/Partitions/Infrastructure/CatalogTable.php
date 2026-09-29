<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;

/**
 * A managed table as the catalog knows it: its oid, schema, row security and the root of its
 * partition tree. The root is the table itself unless the table is a partition of another
 * partitioned table, as `receipts_standard` is of `receipts`. A table partitioned on a sequence
 * comes with its sequence, qualified with the sequence's schema.
 */
#[Internal]
final readonly class CatalogTable
{
    /**
     * @param  string|null  $qualifiedSequence  the sequence of a table partitioned on a sequence, with its schema and quoted; null for a table partitioned on time
     */
    public function __construct(
        public PartitionedTable|SequencePartitionedTable $table,
        public int $oid,
        public string $schema,
        public bool $rowSecurity,
        public bool $forceRowSecurity,
        public string $rootSchema,
        public string $root,
        public ?string $qualifiedSequence = null,
    ) {}

    public function qualifiedName(): string
    {
        return Sql::qualified($this->schema, $this->table->name);
    }

    public function qualifiedRoot(): string
    {
        return Sql::qualified($this->rootSchema, $this->root);
    }

    public function qualifiedPartition(string $partition): string
    {
        return Sql::qualified($this->schema, $partition);
    }
}

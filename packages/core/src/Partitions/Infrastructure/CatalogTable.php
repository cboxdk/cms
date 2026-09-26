<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;

/**
 * A managed table as the catalog knows it: its oid, schema and row security.
 */
#[Internal]
final readonly class CatalogTable
{
    public function __construct(
        public PartitionedTable $table,
        public int $oid,
        public string $schema,
        public bool $rowSecurity,
        public bool $forceRowSecurity,
    ) {}

    public function qualifiedName(): string
    {
        return Sql::qualified($this->schema, $this->table->name);
    }

    public function qualifiedPartition(string $partition): string
    {
        return Sql::qualified($this->schema, $partition);
    }
}

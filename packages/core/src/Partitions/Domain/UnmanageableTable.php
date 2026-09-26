<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * A table in the partition policy cannot be managed as it is in the database. Nothing was changed.
 */
#[Experimental]
final class UnmanageableTable extends LogicException
{
    public static function missing(string $table): self
    {
        return new self(sprintf(
            'The table "%s" is listed in [cms.database.partitions.tables] but does not exist in the owner connection\'s search path. Run the migrations first.',
            $table,
        ));
    }

    public static function notRangePartitioned(string $table): self
    {
        return new self(sprintf(
            'The table "%s" is not partitioned by range. The partition manager keeps range partitions only; create it with PARTITION BY RANGE.',
            $table,
        ));
    }

    public static function hasDefaultPartition(string $table, string $partition): self
    {
        return new self(sprintf(
            'The table "%s" has the DEFAULT partition "%s". Managed tables have none, so a write outside the partitions fails loudly and DETACH CONCURRENTLY is possible. Move its rows and drop it.',
            $table,
            $partition,
        ));
    }

    public static function inTransaction(string $connection): self
    {
        return new self(sprintf(
            'The connection [%s] is inside a transaction. Partition maintenance runs DETACH PARTITION CONCURRENTLY, which Postgres runs only outside a transaction.',
            $connection,
        ));
    }
}

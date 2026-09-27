<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;
use Throwable;

/**
 * A table in the partition policy cannot be managed as it is in the database: it is missing, not
 * partitioned by range or has a DEFAULT partition, or a detached table with the managed name of a
 * partition it needs cannot be attached again.
 *
 * It stops that table only. The partition manager records it in the report's failed list as a
 * FailedTable and goes on with the other tables, as it does with a LockTimeout: one table an
 * operator has to fix must not use up the runway of the others. A missing, list-partitioned or
 * DEFAULT-partitioned table gets no phase of the run; a detached partition that cannot be
 * attached again ends the create phase for its table, with the changes made before it kept, and
 * the table is still retired. Only inTransaction() stops the whole run, before it changes anything.
 */
#[Experimental]
final class UnmanageableTable extends LogicException
{
    public const string CODE = 'partition_table_unmanageable';

    /**
     * @param  string|null  $partition  the partition the table was refused at, or null when the table itself cannot be managed
     */
    private function __construct(
        public readonly ?string $partition,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('[%s] %s', self::CODE, $message), 0, $previous);
    }

    /**
     * @param  string  $connection  the connection that read the catalog: the owner connection for the partition manager, the doctor's for the doctor
     */
    public static function missing(string $table, string $connection): self
    {
        return new self(null, sprintf(
            'The table "%s" is listed in [cbox-cms.database.partitions.tables] but does not exist in the search path of the connection [%s]. Run the migrations first.',
            $table,
            $connection,
        ));
    }

    public static function notRangePartitioned(string $table): self
    {
        return new self(null, sprintf(
            'The table "%s" is not partitioned by range. The partition manager keeps range partitions only; create it with PARTITION BY RANGE.',
            $table,
        ));
    }

    public static function hasDefaultPartition(string $table, string $partition): self
    {
        return new self(null, sprintf(
            'The table "%s" has the DEFAULT partition "%s". Managed tables have none, so a write outside the partitions fails loudly and DETACH CONCURRENTLY is possible. Move its rows and drop it.',
            $table,
            $partition,
        ));
    }

    public static function inTransaction(string $connection): self
    {
        return new self(null, sprintf(
            'The connection [%s] is inside a transaction. Partition maintenance runs DETACH PARTITION CONCURRENTLY, which Postgres runs only outside a transaction.',
            $connection,
        ));
    }

    /**
     * A table with the managed name of a partition the run needs is not a partition, and Postgres
     * refused to attach it for the partition's span: its columns or constraints differ from the
     * parent's, or it holds rows outside the span.
     *
     * @param  Throwable  $refusal  Postgres's error from the attach
     */
    public static function detachedPartition(string $table, string $partition, string $from, string $to, Throwable $refusal): self
    {
        return new self($partition, sprintf(
            'The table "%s" has the managed name of a partition of "%s" that the run needs, but it is not a partition, and attaching it for the span from %s to %s failed. Postgres said: %s. Make it fit the span and the parent, or rename it or drop it, and run partition maintenance again. Until then a write in the span fails.',
            $partition,
            $table,
            $from,
            $to,
            $refusal->getMessage(),
        ), $refusal);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;
use Throwable;

/**
 * A table in the partition policy cannot be managed as it is in the database: it is missing, not
 * partitioned by range or has a DEFAULT partition, the sequence or the retention column of a table
 * partitioned on a sequence is missing or unusable, a detached table with the managed name of a
 * partition it needs cannot be attached again, or Postgres refused a step on one of its
 * partitions for another reason than a lock wait.
 *
 * It stops that table only. The partition manager records it in the report's failed list as a
 * FailedTable and goes on with the other tables, as it does with a LockTimeout: one table an
 * operator has to fix must not use up the runway of the others, or keep them from retirement. A
 * missing, list-partitioned or DEFAULT-partitioned table, or one whose sequence or retention
 * column is missing or unusable, gets no phase of the run. A detached
 * partition that cannot be attached again, or a refused step, ends the phase it happened in for
 * its table, with the changes made before it kept, and the table's next phase still runs. Only
 * inTransaction() stops the whole run, before it changes anything.
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

    /**
     * @param  string  $connection  the connection that read the catalog
     */
    public static function sequenceMissing(string $table, string $sequence, string $connection): self
    {
        return new self(null, sprintf(
            'The sequence "%s" that feeds the key of table "%s" does not exist in the search path of the connection [%s]. Run the migrations first, or name the sequence of the key in [cbox-cms.database.partitions.tables.%s.sequence].',
            $sequence,
            $table,
            $connection,
            $table,
        ));
    }

    public static function sequenceNotAscending(string $table, string $sequence, int $increment, int $minimum): self
    {
        return new self(null, sprintf(
            'The sequence "%s" of table "%s" has the increment %d and the minimum %d. The partition manager keeps partitions ahead of a sequence that counts up from 0 or more; use an increment of at least 1 and a minimum of at least 0.',
            $sequence,
            $table,
            $increment,
            $minimum,
        ));
    }

    public static function sequenceUnreadable(string $table, string $sequence, string $role): self
    {
        return new self(null, sprintf(
            'The role "%s" may not read the sequence "%s" of table "%s", so the runway ahead of it cannot be measured. Grant it SELECT on the sequence.',
            $role,
            $sequence,
            $table,
        ));
    }

    public static function retentionColumn(string $table, string $column): self
    {
        return new self(null, sprintf(
            'The table "%s" has no column "%s" of type timestamptz, which [cbox-cms.database.partitions.tables.%s.retention_column] names. Retention reads the age of a partition\'s newest row from it; name a timestamptz column of the table.',
            $table,
            $column,
            $table,
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

    /**
     * Postgres refused a step on a partition of the table for another reason than a lock wait,
     * such as a DROP TABLE that an object depending on the partition blocks, or a CREATE TABLE of
     * a managed name that another relation holds: a view, or a partition of another parent. Every
     * later run fails at the same step until an operator removes the cause.
     *
     * @param  Throwable  $refusal  Postgres's error from the step
     */
    public static function stepRefused(string $table, DdlStep $step, string $partition, Throwable $refusal): self
    {
        return new self($partition, sprintf(
            'Postgres refused the step "%s" for the partition "%s" of "%s". Postgres said: %s. The run went on with the other tables. Remove what Postgres names, such as an object that depends on the partition or a relation that holds its managed name, and run partition maintenance again.',
            $step->value,
            $partition,
            $table,
            $refusal->getMessage(),
        ), $refusal);
    }
}

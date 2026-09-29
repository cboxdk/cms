<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Boundary\MissingPartition;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Turns Postgres' "no partition found" error into PartitionMissing, for the adapters that write to
 * partitioned tables. Boundary\MissingPartition reads the error: SQLSTATE 23514 with the message
 * `no partition of relation "<table>" found for row`, and not a violated CHECK constraint, which
 * has the same SQLSTATE.
 *
 *     try {
 *         $connection->insert($sql, $bindings);
 *     } catch (QueryException $exception) {
 *         throw MissingPartitionMapper::map($exception);
 *     }
 */
#[Experimental]
final readonly class MissingPartitionMapper
{
    /**
     * PartitionMissing when the error is a row that no partition covers, the exception itself
     * otherwise.
     */
    public static function map(QueryException $exception): Throwable
    {
        return self::partitionMissing($exception) ?? $exception;
    }

    public static function partitionMissing(QueryException $exception): ?PartitionMissing
    {
        return MissingPartition::of($exception);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Turns Postgres' "no partition found" error into PartitionMissing, for the adapters that write to
 * partitioned tables.
 *
 * Postgres reports a row that no partition covers as SQLSTATE 23514 with the message
 * `no partition of relation "<table>" found for row`. The same SQLSTATE is also a violated CHECK
 * constraint, so the message decides. The message is Postgres' English text; the operating
 * contract keeps lc_messages in English so errors can be read.
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
    private const string MESSAGE = '/no partition of relation "(.+?)" found for row/';

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
        $error = SqlError::of($exception);

        if (! $error->is(SqlError::CHECK_VIOLATION) || preg_match(self::MESSAGE, $error->message, $match) !== 1) {
            return null;
        }

        return PartitionMissing::forTable($match[1], $exception);
    }
}

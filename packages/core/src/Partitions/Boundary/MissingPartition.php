<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Illuminate\Database\QueryException;

/**
 * Reads Postgres' "no partition found" error, for the code that writes to partitioned tables:
 * the adapters through Adapter\MissingPartitionMapper, and Infrastructure, which may use Boundary
 * but not Adapter, directly.
 *
 * Postgres reports a row that no partition covers as SQLSTATE 23514 with the message
 * `no partition of relation "<table>" found for row`. The same SQLSTATE is also a violated CHECK
 * constraint, so the message decides. The message is Postgres' English text; the operating
 * contract keeps lc_messages in English so errors can be read (PRD 4.2), and cms:doctor checks it
 * in postgres.lc_messages.
 */
#[Internal]
final readonly class MissingPartition
{
    private const string MESSAGE = '/no partition of relation "(.+?)" found for row/';

    /**
     * PartitionMissing when the error is a row that no partition covers, null otherwise.
     */
    public static function of(QueryException $exception): ?PartitionMissing
    {
        $error = SqlError::of($exception);

        if (! $error->is(SqlError::CHECK_VIOLATION) || preg_match(self::MESSAGE, $error->message, $match) !== 1) {
            return null;
        }

        return PartitionMissing::forTable($match[1], $exception);
    }
}

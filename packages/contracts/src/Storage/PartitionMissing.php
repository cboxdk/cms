<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Storage;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;
use Throwable;

/**
 * A write reached a partitioned table where no partition covers the row (PRD 4, 4.2).
 *
 * Partitioned tables have no DEFAULT partition, so a row outside the partitions that exist fails
 * loudly instead of landing in a catch-all. Adapters that write to partitioned tables turn
 * Postgres' error into this one, with the error code CODE. The usual cause is that partition
 * maintenance has not run: the scheduled command `cms:partitions:maintain` creates partitions
 * ahead of the clock, and a stopped scheduler lets the runway run out.
 */
#[Experimental]
final class PartitionMissing extends RuntimeException
{
    public const string CODE = 'partition_missing';

    private function __construct(
        public readonly string $table,
        string $message,
        ?Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  string  $table  the partitioned table the write went to, as Postgres names it
     */
    public static function forTable(string $table, ?Throwable $previous = null): self
    {
        return new self($table, sprintf(
            '[%s] No partition of table "%s" covers the row. Partitioned tables have no DEFAULT partition, so a write outside the partitions that exist fails. Run `php artisan cms:partitions:maintain` and check that the scheduler runs it; the command creates partitions ahead of the clock.',
            self::CODE,
            $table,
        ), $previous);
    }
}

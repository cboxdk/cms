<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the partition manager runs (PRD 4.2): which connection runs the DDL, how far ahead it
 * creates partitions, how long each DDL statement may wait for a lock, and how often it tries.
 *
 * Read from `cbox-cms.database` by PartitionConfig.
 */
#[Experimental]
final readonly class PartitionPolicy
{
    public const int DEFAULT_RUNWAY_DAYS = 14;

    /** PRD 4.2: DDL runs with lock_timeout 2 s and retries. */
    public const int DEFAULT_LOCK_TIMEOUT_MS = 2000;

    public const int DEFAULT_ATTEMPTS = 3;

    public const int DEFAULT_BACKOFF_MS = 250;

    /**
     * @param  string  $ownerConnection  the database connection of the owner role; the app role has no DDL
     * @param  list<PartitionedTable>  $tables
     * @param  int  $runwayDays  how many days ahead of the clock partitions exist
     * @param  int  $lockTimeoutMs  the lock_timeout of every DDL statement
     * @param  int  $attempts  how many times a DDL step is tried before it gives up with LockTimeout
     * @param  int  $backoffMs  the wait before the second attempt; it doubles for each attempt after that
     */
    public function __construct(
        public string $ownerConnection,
        public array $tables,
        public int $runwayDays = self::DEFAULT_RUNWAY_DAYS,
        public int $lockTimeoutMs = self::DEFAULT_LOCK_TIMEOUT_MS,
        public int $attempts = self::DEFAULT_ATTEMPTS,
        public int $backoffMs = self::DEFAULT_BACKOFF_MS,
    ) {
        if ($ownerConnection === '') {
            throw InvalidPartitionPolicy::value('owner_connection', 'a connection name', $ownerConnection);
        }

        if ($runwayDays < 1 || $runwayDays > 366) {
            throw InvalidPartitionPolicy::value('partitions.runway_days', 'from 1 to 366', (string) $runwayDays);
        }

        if ($lockTimeoutMs < 1 || $lockTimeoutMs > 60_000) {
            throw InvalidPartitionPolicy::value('partitions.lock_timeout_ms', 'from 1 to 60000', (string) $lockTimeoutMs);
        }

        if ($attempts < 1 || $attempts > 10) {
            throw InvalidPartitionPolicy::value('partitions.attempts', 'from 1 to 10', (string) $attempts);
        }

        if ($backoffMs < 0 || $backoffMs > 60_000) {
            throw InvalidPartitionPolicy::value('partitions.backoff_ms', 'from 0 to 60000', (string) $backoffMs);
        }

        $names = array_map(static fn (PartitionedTable $table): string => $table->name, $tables);

        if (count($names) !== count(array_unique($names))) {
            throw InvalidPartitionPolicy::duplicateTables($names);
        }
    }

    /**
     * The lock timeout as Postgres writes it: `2s`, or `1500ms` when it is not whole seconds.
     */
    public function lockTimeoutSetting(): string
    {
        return $this->lockTimeoutMs % 1000 === 0
            ? sprintf('%ds', intdiv($this->lockTimeoutMs, 1000))
            : sprintf('%dms', $this->lockTimeoutMs);
    }

    /**
     * The wait before the given attempt, in milliseconds: none before the first, then the backoff,
     * doubling each time.
     */
    public function backoffBefore(int $attempt): int
    {
        return $attempt <= 1 ? 0 : $this->backoffMs * (2 ** ($attempt - 2));
    }
}

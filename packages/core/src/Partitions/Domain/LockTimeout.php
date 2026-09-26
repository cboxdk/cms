<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;
use Throwable;

/**
 * Partition maintenance gave up on a step because another session held a conflicting lock for
 * longer than the lock timeout, on every attempt (PRD 4.2: lock_timeout 2 s with retries).
 *
 * The step changed nothing: a create runs in one transaction, and a detach starts only when the
 * sessions that held locks on the parent have finished. The partitions done before the step stay
 * done, and the next run continues. If a detach was interrupted after its first phase anyway, the
 * next run finalizes it.
 */
#[Experimental]
final class LockTimeout extends RuntimeException
{
    public const string CODE = 'partition_lock_timeout';

    private function __construct(
        public readonly DdlStep $step,
        public readonly ?string $table,
        public readonly ?string $partition,
        public readonly int $attempts,
        string $message,
        ?Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  string|null  $table  null for the maintenance lock, which covers every table
     */
    public static function gaveUp(DdlStep $step, ?string $table, ?string $partition, int $attempts, string $lockTimeout, ?Throwable $previous = null): self
    {
        $subject = match (true) {
            $table === null => 'the partition maintenance lock',
            $partition === null => sprintf('table "%s"', $table),
            default => sprintf('partition "%s" of table "%s"', $partition, $table),
        };

        return new self($step, $table, $partition, $attempts, sprintf(
            '[%s] Gave up on step "%s" for %s after %d %s: each time another session held a conflicting lock for longer than lock_timeout %s. The step changed nothing, and the next run tries again. To see what holds the lock, query pg_locks joined with pg_stat_activity.',
            self::CODE,
            $step->value,
            $subject,
            $attempts,
            $attempts === 1 ? 'attempt' : 'attempts',
            $lockTimeout,
        ), $previous);
    }
}

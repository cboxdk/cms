<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Runs one step of partition DDL with the lock timeout and the bounded retry of PRD 4.2.
 *
 * Every attempt sets `lock_timeout` on the session first and resets it after, so every statement
 * in the step runs under it. A lock wait that passes it (SQLSTATE 55P03), or a LockWaitExpired
 * from the wait for older lockers, is retried after a backoff that doubles. After the last attempt
 * the step throws LockTimeout. Any other error is thrown at once. A transaction the step left
 * open is rolled back, so an attempt that fails leaves nothing behind.
 */
#[Internal]
final readonly class LockedDdl
{
    public function __construct(
        private Connection $connection,
        private PartitionPolicy $policy,
    ) {}

    /**
     * @param  string|null  $table  null for the maintenance lock
     * @param  Closure(): void  $work
     *
     * @throws LockTimeout
     * @throws QueryException when a statement fails for another reason than a lock wait
     */
    public function run(DdlStep $step, ?string $table, ?string $partition, Closure $work): void
    {
        $setting = $this->policy->lockTimeoutSetting();

        for ($attempt = 1; ; $attempt++) {
            $wait = $this->policy->backoffBefore($attempt);

            if ($wait > 0) {
                usleep($wait * 1000);
            }

            try {
                $this->attempt($setting, $work);

                return;
            } catch (QueryException $exception) {
                if (! SqlError::of($exception)->is(SqlError::LOCK_NOT_AVAILABLE)) {
                    throw $exception;
                }

                $failure = $exception;
            } catch (LockWaitExpired $exception) {
                $failure = $exception;
            }

            if ($attempt >= $this->policy->attempts) {
                throw LockTimeout::gaveUp($step, $table, $partition, $attempt, $setting, $failure);
            }
        }
    }

    /**
     * @param  Closure(): void  $work
     */
    private function attempt(string $setting, Closure $work): void
    {
        $this->connection->statement(sprintf('set lock_timeout = %s', Sql::literal($setting)));

        try {
            $work();
        } finally {
            if ($this->connection->transactionLevel() > 0) {
                $this->connection->rollBack(0);
            }

            $this->connection->statement('reset lock_timeout');
        }
    }
}

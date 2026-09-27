<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;

/**
 * One step of partition maintenance that gave up on a busy lock after every attempt, as the run's
 * report holds it: the values of the LockTimeout, without the exception and its stack.
 */
#[Experimental]
final readonly class GaveUpStep
{
    /**
     * @param  string|null  $table  null for the maintenance lock, which covers every table
     * @param  string|null  $partition  null when the step was not about one partition
     * @param  int  $attempts  how many times the step was tried
     * @param  string  $message  the LockTimeout's message, which starts with [partition_lock_timeout]
     * @param  string|null  $cause  the message of the last attempt's lock failure, such as Postgres's
     *                              55P03 with the statement that waited, or null when there was none
     */
    public function __construct(
        public DdlStep $step,
        public ?string $table,
        public ?string $partition,
        public int $attempts,
        public string $message,
        public ?string $cause,
    ) {}

    public static function of(LockTimeout $timeout): self
    {
        return new self(
            $timeout->step,
            $timeout->table,
            $timeout->partition,
            $timeout->attempts,
            $timeout->getMessage(),
            $timeout->getPrevious()?->getMessage(),
        );
    }
}

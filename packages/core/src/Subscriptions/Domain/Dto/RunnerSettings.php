<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use InvalidArgumentException;

/**
 * The event runner's settings, from cbox-cms.events.runner (PRD 7.4 to 7.8):
 *
 * - serviceActor: the service actor the subscribers run as, or null when none is configured.
 * - batchSize: the most events one batch reads, 1 to MAX_BATCH_SIZE.
 * - batchBudgetMs: how long a batch may hand events to its subscribers before it commits, 1 to
 *   MAX_BATCH_BUDGET_MS, so each batch's transaction stays under 2 seconds (PRD 7.4).
 * - maxAttempts: the tries of one event before its aggregate is parked, 1 to MAX_ATTEMPTS (PRD 7.8).
 * - backoffBaseMs and backoffMaxMs: the wait after the first failed try, doubled after each further
 *   one up to the maximum (PRD 7.7).
 * - idleSleepMs: the wait when no subscription of the lane had anything to do.
 */
#[Experimental]
final readonly class RunnerSettings
{
    public const int MAX_BATCH_SIZE = 10_000;

    public const int MAX_BATCH_BUDGET_MS = 1_900;

    public const int MAX_ATTEMPTS = 100;

    public const int MAX_WAIT_MS = 60_000;

    public function __construct(
        public ?ActorId $serviceActor,
        public int $batchSize = 100,
        public int $batchBudgetMs = 1_000,
        public int $maxAttempts = 5,
        public int $backoffBaseMs = 100,
        public int $backoffMaxMs = 5_000,
        public int $idleSleepMs = 200,
    ) {
        $this->check('batch_size', $batchSize, 1, self::MAX_BATCH_SIZE);
        $this->check('batch_budget_ms', $batchBudgetMs, 1, self::MAX_BATCH_BUDGET_MS);
        $this->check('max_attempts', $maxAttempts, 1, self::MAX_ATTEMPTS);
        $this->check('backoff_base_ms', $backoffBaseMs, 1, self::MAX_WAIT_MS);
        $this->check('backoff_max_ms', $backoffMaxMs, $backoffBaseMs, self::MAX_WAIT_MS);
        $this->check('idle_sleep_ms', $idleSleepMs, 1, self::MAX_WAIT_MS);
    }

    /**
     * The wait after the given number of failed tries in a row: backoffBaseMs after the first,
     * doubled after each further one, at most backoffMaxMs.
     */
    public function backoff(int $failures): int
    {
        $wait = $this->backoffBaseMs;

        for ($failure = 1; $failure < $failures && $wait < $this->backoffMaxMs; $failure++) {
            $wait *= 2;
        }

        return min($wait, $this->backoffMaxMs);
    }

    private function check(string $name, int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('The setting cbox-cms.events.runner.%s must be a whole number from %d to %d; it is %d.', $name, $minimum, $maximum, $value));
        }
    }
}

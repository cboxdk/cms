<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * How long a command waits after its commit for the wait level its envelope asks for (PRD 8.4),
 * from `cbox-cms.receipts.wait_budget_ms`. The wait runs after the command transaction has
 * committed, so it holds no lock and no transaction, and it is real time, not the Clock. A call
 * whose level is not reached within it returns committed_wait_timeout: committed, but not waited
 * out. 0 means the call never waits past commit.
 */
#[Internal]
final readonly class WaitSettings
{
    /** The most a call may wait: a request's usual limit at a gateway. */
    public const int MAX_MILLISECONDS = 30_000;

    /**
     * @throws InvalidArgumentException when the budget is below 0 or above MAX_MILLISECONDS
     */
    public function __construct(public int $budgetMilliseconds)
    {
        if ($budgetMilliseconds < 0 || $budgetMilliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf('A wait budget is 0 to %d ms; it is %d.', self::MAX_MILLISECONDS, $budgetMilliseconds));
        }
    }
}

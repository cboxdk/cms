<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;

/**
 * The kernel's settings for idempotency keys (PRD 6.1), from `cbox-cms.idempotency`.
 *
 * waitBudget is how long a command waits, by default, for another call with the same key to end
 * before it is rejected with idempotency_in_flight, which the client may retry. It is part of the
 * command transaction's 5 seconds (GUARDRAILS 4.1), so it leaves the rest for the command itself.
 */
#[Internal]
final readonly class IdempotencySettings
{
    public function __construct(public WaitBudget $waitBudget) {}
}

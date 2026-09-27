<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How long IdempotencyStore::claim() waits for another transaction's claim on the same key to end
 * (PRD 6.1: concurrent calls with the same key wait for the first). When the budget runs out, the
 * claim is InFlight.
 *
 * The budget is real elapsed time that the store measures, not time read from the Clock; the Clock
 * decides only when a record expires. It is 0 to MAX_MILLISECONDS: the claim runs inside the
 * command transaction, which may take at most 5 seconds (GUARDRAILS 4.1), so the kernel picks a
 * budget that leaves time for the command itself. 0 means do not wait.
 *
 * The budget is an upper bound. A store whose database ends a transaction after a time limit, such
 * as Postgres' transaction_timeout, ends the wait sooner, with InFlight, while the transaction still
 * has time to roll back; the time limit counts from the start of the transaction, so a budget that
 * reaches past it is clamped there.
 */
#[Experimental]
final readonly class WaitBudget
{
    public const int MAX_MILLISECONDS = 5000;

    public function __construct(public int $milliseconds)
    {
        if ($milliseconds < 0 || $milliseconds > self::MAX_MILLISECONDS) {
            throw InvalidIdempotencyValue::waitBudget($milliseconds);
        }
    }

    public static function milliseconds(int $milliseconds): self
    {
        return new self($milliseconds);
    }

    /**
     * A budget that does not wait: a claim held by another transaction is InFlight at once.
     */
    public static function none(): self
    {
        return new self(0);
    }
}

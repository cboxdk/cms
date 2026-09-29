<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Another open transaction holds the claim on the key, and it did not end within the wait budget.
 * The first call is still running, so the outcome is not known yet. The caller rejects the command
 * with the error code CODE, which the client may retry later, and does not run it. The caller's
 * own transaction stays usable, and this claim holds nothing.
 */
#[Experimental]
final readonly class InFlight implements ClaimResult
{
    public const string CODE = 'idempotency_in_flight';

    public function __construct(
        public IdempotencyScope $scope,
        public IdempotencyKey $key,
        public WaitBudget $waited,
    ) {}
}

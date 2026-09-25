<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The key was completed with another content hash (PRD 6.1). The command is rejected with the
 * error code CODE, and the stored record is left as it was.
 */
#[Experimental]
final readonly class Conflict implements ClaimResult
{
    public const string CODE = 'idempotency_conflict';

    public function __construct(
        public IdempotencyScope $scope,
        public IdempotencyKey $key,
    ) {}
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * No completed record for the key: the caller's transaction now holds the claim and runs the
 * command. When the command commits a changeset, the caller passes the token to
 * IdempotencyStore::complete() in the same transaction. A rejected command does not call it, so
 * the key stays fresh and a retry runs again.
 */
#[Experimental]
final readonly class Fresh implements ClaimResult
{
    public function __construct(public ClaimToken $token) {}
}

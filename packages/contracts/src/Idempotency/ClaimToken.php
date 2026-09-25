<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The claim a Fresh result hands out: the scope, key and content hash that
 * IdempotencyStore::complete() records with the changeset. The token is valid only in the
 * transaction that made the claim, and only until complete() has used it once.
 */
#[Experimental]
final readonly class ClaimToken
{
    public function __construct(
        public IdempotencyScope $scope,
        public IdempotencyKey $key,
        public ContentHash $hash,
    ) {}

    public function equals(self $other): bool
    {
        return $this->scope->equals($other->scope)
            && $this->key->equals($other->key)
            && $this->hash->equals($other->hash);
    }
}

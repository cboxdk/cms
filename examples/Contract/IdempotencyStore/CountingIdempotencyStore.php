<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * An IdempotencyStore that an application binds in cbox-cms.contracts in place of the default. It
 * passes every call to the store it decorates and counts the claims by result, for a metric.
 */
final class CountingIdempotencyStore implements IdempotencyStore
{
    /** @var array<class-string<ClaimResult>, int> */
    private array $claims = [];

    public function __construct(private readonly IdempotencyStore $store) {}

    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        $result = $this->store->claim($scope, $key, $hash, $waitBudget);
        $this->claims[$result::class] = $this->claims($result::class) + 1;

        return $result;
    }

    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        $this->store->complete($token, $changesetId);
    }

    /**
     * How many claims had the result.
     *
     * @param  class-string<ClaimResult>  $result
     */
    public function claims(string $result): int
    {
        return $this->claims[$result] ?? 0;
    }
}

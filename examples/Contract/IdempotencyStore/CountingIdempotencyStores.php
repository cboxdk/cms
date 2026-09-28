<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Testkit\Idempotency\HolderEnd;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use DateTimeImmutable;

/**
 * The harness the shared suite runs CountingIdempotencyStore through. It wraps the harness of the
 * decorated store: each session is a CountingIdempotencySession over one of that harness's
 * sessions, and uncover() and holdWhileWaiting() go to the decorated store's harness.
 */
final readonly class CountingIdempotencyStores implements IdempotencyStoreHarness
{
    public function __construct(private IdempotencyStoreHarness $stores) {}

    public function session(): CountingIdempotencySession
    {
        return new CountingIdempotencySession($this->stores->session());
    }

    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->stores->uncover($from, $to);
    }

    public function holdWhileWaiting(
        IdempotencyScope $scope,
        IdempotencyKey $key,
        ContentHash $hash,
        ?ChangesetId $changesetId,
        HolderEnd $end,
        int $afterMilliseconds,
    ): void {
        $this->stores->holdWhileWaiting($scope, $key, $hash, $changesetId, $end, $afterMilliseconds);
    }
}

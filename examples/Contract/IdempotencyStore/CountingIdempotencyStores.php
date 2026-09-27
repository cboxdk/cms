<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use DateTimeImmutable;

/**
 * The harness the shared suite runs CountingIdempotencyStore through. It wraps the harness of the
 * decorated store: each session is a CountingIdempotencySession over one of that harness's
 * sessions, and uncover() takes the dates out of the decorated store's partitions.
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
}

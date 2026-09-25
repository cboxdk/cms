<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdempotencyStore contract suite against the in-memory fake and its sessions.
 */
final class FakeIdempotencyStoreContractTest extends TestCase
{
    use IdempotencyStoreContract;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        return new FakeIdempotencyStore($clock);
    }
}

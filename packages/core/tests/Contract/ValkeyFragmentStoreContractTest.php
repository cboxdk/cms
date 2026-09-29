<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore;
use Cbox\Cms\Testkit\Cache\FragmentStoreContract;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory;
use Override;

/**
 * The shared FragmentStore contract suite against the core's Valkey store, on real Valkey through
 * the testkit's RealValkey harness: the test database index and a key prefix per run, removed
 * after each test (GUARDRAILS 9: the same suite against the fake and the real adapter). It runs in
 * the Contract suite beside the fake's, so one filter runs both; it needs the services of
 * `composer services:up`, as the Postgres suite does.
 */
final class ValkeyFragmentStoreContractTest extends TestCase
{
    use FragmentStoreContract;
    use RealValkey;

    #[Override]
    protected function fragmentStore(Clock $clock): FragmentStore
    {
        return new ValkeyFragmentStore($this->app?->make(Factory::class) ?? self::fail('The application has not booted.'), $clock);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Cache\FakeFragmentStore;
use Cbox\Cms\Testkit\Cache\FragmentStoreContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared FragmentStore contract suite against the in-memory fake.
 */
final class FakeFragmentStoreContractTest extends TestCase
{
    use FragmentStoreContract;

    #[Override]
    protected function fragmentStore(Clock $clock): FragmentStore
    {
        return new FakeFragmentStore($clock);
    }
}

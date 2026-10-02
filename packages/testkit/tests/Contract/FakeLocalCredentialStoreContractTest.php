<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;
use Cbox\Cms\Testkit\Identity\LocalCredentialStoreContract;
use Cbox\Cms\Testkit\Identity\LocalCredentialStoreHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared LocalCredentialStore contract suite against the fake.
 */
final class FakeLocalCredentialStoreContractTest extends TestCase
{
    use LocalCredentialStoreContract;

    #[Override]
    protected function harness(): LocalCredentialStoreHarness
    {
        return new FakeLocalCredentialStore;
    }
}

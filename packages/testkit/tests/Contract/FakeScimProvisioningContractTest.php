<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning;
use Cbox\Cms\Testkit\Provisioning\ScimProvisioningContract;
use Cbox\Cms\Testkit\Provisioning\ScimProvisioningHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared ScimProvisioning contract suite against the fake.
 */
final class FakeScimProvisioningContractTest extends TestCase
{
    use ScimProvisioningContract;

    #[Override]
    protected function scim(): ScimProvisioningHarness
    {
        return new FakeScimProvisioning;
    }
}

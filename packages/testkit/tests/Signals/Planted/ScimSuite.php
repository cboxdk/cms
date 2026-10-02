<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Testkit\Provisioning\ScimProvisioningContract;
use Cbox\Cms\Testkit\Provisioning\ScimProvisioningHarness;
use Closure;
use Override;

/**
 * ScimProvisioningContract against the provisionings a closure plants, one per case.
 */
final readonly class ScimSuite
{
    use ScimProvisioningContract;

    /**
     * @param  Closure(): ScimProvisioningHarness  $plant
     */
    public function __construct(private Closure $plant) {}

    #[Override]
    protected function scim(): ScimProvisioningHarness
    {
        return ($this->plant)();
    }
}

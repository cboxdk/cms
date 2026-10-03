<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Core\Tests\Egress\SsrfEgressGatewayHarness;
use Cbox\Cms\Testkit\Egress\EgressGatewayContract;
use Cbox\Cms\Testkit\Egress\EgressGatewayHarness;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared EgressGateway contract suite against SsrfEgressGateway, the kernel's default, with its
 * guard and HTTP client and the transport and DNS faked.
 */
final class SsrfEgressGatewayContractTest extends TestCase
{
    use EgressGatewayContract;

    #[Override]
    protected function harness(): EgressGatewayHarness
    {
        return new SsrfEgressGatewayHarness($this->app ?? self::fail('No application.'));
    }
}

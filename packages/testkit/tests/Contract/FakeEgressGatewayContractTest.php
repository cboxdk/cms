<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Egress\EgressGatewayContract;
use Cbox\Cms\Testkit\Egress\EgressGatewayHarness;
use Cbox\Cms\Testkit\Tests\Egress\FakeEgressGatewayHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared EgressGateway contract suite against the testkit's FakeEgressGateway.
 */
final class FakeEgressGatewayContractTest extends TestCase
{
    use EgressGatewayContract;

    #[Override]
    protected function harness(): EgressGatewayHarness
    {
        return new FakeEgressGatewayHarness;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Egress\MailGatewayContract;
use Cbox\Cms\Testkit\Egress\MailGatewayHarness;
use Cbox\Cms\Testkit\Tests\Egress\FakeMailGatewayHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared MailGateway contract suite against the testkit's FakeMailGateway.
 */
final class FakeMailGatewayContractTest extends TestCase
{
    use MailGatewayContract;

    #[Override]
    protected function harness(): MailGatewayHarness
    {
        return new FakeMailGatewayHarness;
    }
}

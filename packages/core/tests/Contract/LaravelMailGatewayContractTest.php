<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Core\Tests\Egress\LaravelMailGatewayHarness;
use Cbox\Cms\Testkit\Egress\MailGatewayContract;
use Cbox\Cms\Testkit\Egress\MailGatewayHarness;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared MailGateway contract suite against LaravelMailGateway, the kernel's default, on
 * Laravel's mail manager with a transport that keeps the mails it takes.
 */
final class LaravelMailGatewayContractTest extends TestCase
{
    use MailGatewayContract;

    #[Override]
    protected function harness(): MailGatewayHarness
    {
        return new LaravelMailGatewayHarness($this->app ?? self::fail('No application.'));
    }
}

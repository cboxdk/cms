<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;
use Cbox\Cms\Core\Egress\Domain\MailGateway;
use Cbox\Cms\Core\Tests\Egress\Fakes\FakeMailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * MailGatewayBehaviour against the fake the tests of the gateway's callers use.
 */
final class FakeMailGatewayBehaviourTest extends TestCase
{
    use MailGatewayBehaviour;

    private ?FakeMailGateway $gateway = null;

    #[Override]
    protected function gateway(FakeTelemetry $telemetry): MailGateway
    {
        return $this->gateway = new FakeMailGateway($telemetry);
    }

    #[Override]
    protected function breakTransport(): void
    {
        $this->fake()->goDown();
    }

    #[Override]
    protected function delivered(): array
    {
        return array_map(static fn (OutboundMail $mail): array => [$mail->to->value, $mail->subject, $mail->text], $this->fake()->sent());
    }

    private function fake(): FakeMailGateway
    {
        return $this->gateway ?? self::fail('The gateway was not made.');
    }
}

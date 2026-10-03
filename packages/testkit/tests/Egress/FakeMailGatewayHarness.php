<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Egress;

use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Testkit\Egress\FakeMailGateway;
use Cbox\Cms\Testkit\Egress\MailGatewayHarness;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use LogicException;
use Override;

/**
 * The harness of the shared MailGatewayContract for the testkit's FakeMailGateway.
 */
final class FakeMailGatewayHarness implements MailGatewayHarness
{
    private ?FakeMailGateway $gateway = null;

    #[Override]
    public function gateway(FakeTelemetry $telemetry): MailGateway
    {
        return $this->gateway = new FakeMailGateway($telemetry);
    }

    #[Override]
    public function breakTransport(): void
    {
        $this->fake()->goDown();
    }

    #[Override]
    public function delivered(): array
    {
        return array_map(static fn (OutboundMail $mail): array => [$mail->to->value, $mail->subject, $mail->text], $this->fake()->sent());
    }

    private function fake(): FakeMailGateway
    {
        return $this->gateway ?? throw new LogicException('The gateway was not made.');
    }
}

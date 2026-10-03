<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Egress;

use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressResponse;
use Cbox\Cms\Testkit\Egress\EgressGatewayHarness;
use Cbox\Cms\Testkit\Egress\FakeEgressGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use LogicException;
use Override;

/**
 * The harness of the shared EgressGatewayContract for the testkit's FakeEgressGateway.
 */
final class FakeEgressGatewayHarness implements EgressGatewayHarness
{
    private ?FakeEgressGateway $gateway = null;

    #[Override]
    public function gateway(FakeTelemetry $telemetry): EgressGateway
    {
        return $this->gateway = new FakeEgressGateway($telemetry);
    }

    #[Override]
    public function answer(string $url, int $status, string $body = ''): void
    {
        $this->fake()->answer($url, $status >= 300 && $status < 400 ? $status : new EgressResponse($status, $body));
    }

    #[Override]
    public function pointAtPrivateAddress(string $host): void
    {
        $this->fake()->block($host);
    }

    #[Override]
    public function goDown(string $url): void
    {
        $this->fake()->goDown();
    }

    #[Override]
    public function sentHeader(string $name): ?string
    {
        $requests = $this->fake()->requests();
        $last = $requests === [] ? null : $requests[count($requests) - 1];

        foreach ($last->headers ?? [] as $header) {
            if (strcasecmp($header->name, $name) === 0) {
                return $header->value;
            }
        }

        return null;
    }

    private function fake(): FakeEgressGateway
    {
        return $this->gateway ?? throw new LogicException('The gateway was not made.');
    }
}

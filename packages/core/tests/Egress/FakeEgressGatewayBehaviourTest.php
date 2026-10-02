<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Tests\Egress\Fakes\FakeEgressGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * EgressGatewayBehaviour against the fake the tests of the gateway's callers use.
 */
final class FakeEgressGatewayBehaviourTest extends TestCase
{
    use EgressGatewayBehaviour;

    private ?FakeEgressGateway $gateway = null;

    #[Override]
    protected function gateway(FakeTelemetry $telemetry): EgressGateway
    {
        return $this->gateway = new FakeEgressGateway($telemetry);
    }

    #[Override]
    protected function answer(string $url, int $status, string $body = ''): void
    {
        $this->fake()->answer($url, $status >= 300 && $status < 400 ? $status : new EgressResponse($status, $body));
    }

    #[Override]
    protected function pointAtPrivateAddress(string $host): void
    {
        $this->fake()->block($host);
    }

    #[Override]
    protected function goDown(string $url): void
    {
        $this->fake()->goDown();
    }

    #[Override]
    protected function sentHeader(string $name): ?string
    {
        $requests = $this->fake()->requests();
        $last = $requests[array_key_last($requests) ?? -1] ?? null;

        foreach ($last->headers ?? [] as $header) {
            if (strcasecmp($header->name, $name) === 0) {
                return $header->value;
            }
        }

        return null;
    }

    private function fake(): FakeEgressGateway
    {
        return $this->gateway ?? self::fail('The gateway was not made.');
    }
}

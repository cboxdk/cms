<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * EgressGatewayBehaviour against the real gateway, its guard and its HTTP client, with the
 * transport and DNS faked (SsrfEgressGatewayWorld).
 */
final class SsrfEgressGatewayBehaviourTest extends TestCase
{
    use EgressGatewayBehaviour;

    private ?SsrfEgressGatewayWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->world = new SsrfEgressGatewayWorld(app());
    }

    #[Override]
    protected function gateway(FakeTelemetry $telemetry): EgressGateway
    {
        return $this->world()->gateway($telemetry);
    }

    #[Override]
    protected function answer(string $url, int $status, string $body = ''): void
    {
        $this->world()->answer($url, $status, $body);
    }

    #[Override]
    protected function pointAtPrivateAddress(string $host): void
    {
        $this->world()->resolver->set($host, ['10.0.0.5']);
    }

    #[Override]
    protected function goDown(string $url): void
    {
        $this->world()->goDown($url);
    }

    #[Override]
    protected function sentHeader(string $name): ?string
    {
        $sent = $this->world()->sent();
        $last = $sent === [] ? null : $sent[count($sent) - 1];
        $first = $last === null ? null : ($last->header($name)[0] ?? null);

        return is_string($first) ? $first : null;
    }

    private function world(): SsrfEgressGatewayWorld
    {
        return $this->world ?? self::fail('The world was not set up.');
    }
}

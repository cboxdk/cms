<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Testkit\Egress\EgressGatewayHarness;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * The harness of the shared EgressGatewayContract for the real gateway, its guard and its HTTP
 * client, with the transport and DNS faked (SsrfEgressGatewayWorld), so no request leaves the test.
 */
final readonly class SsrfEgressGatewayHarness implements EgressGatewayHarness
{
    private SsrfEgressGatewayWorld $world;

    public function __construct(Application $app)
    {
        $this->world = new SsrfEgressGatewayWorld($app);
    }

    #[Override]
    public function gateway(FakeTelemetry $telemetry): EgressGateway
    {
        return $this->world->gateway($telemetry);
    }

    #[Override]
    public function answer(string $url, int $status, string $body = ''): void
    {
        $this->world->answer($url, $status, $body);
    }

    #[Override]
    public function pointAtPrivateAddress(string $host): void
    {
        $this->world->resolver->set($host, ['10.0.0.5']);
    }

    #[Override]
    public function goDown(string $url): void
    {
        $this->world->goDown($url);
    }

    #[Override]
    public function sentHeader(string $name): ?string
    {
        $sent = $this->world->sent();
        $last = $sent === [] ? null : $sent[count($sent) - 1];
        $first = $last === null ? null : ($last->header($name)[0] ?? null);

        return is_string($first) ? $first : null;
    }
}

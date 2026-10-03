<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * What the shared suite EgressGatewayContract needs besides the gateway: the network's side of it.
 * A harness for a real gateway fakes the transport and DNS, so no request leaves the test, and keeps
 * the gateway's own guard.
 *
 * - gateway() is the gateway under test, counting on the given telemetry.
 * - answer() makes a URL, on a host that resolves to a public address, answer with a status and body.
 * - pointAtPrivateAddress() makes a host resolve to a private address.
 * - goDown() makes a URL's destination not answer.
 * - sentHeader() is the value of a header in the last request that reached the transport.
 */
#[Experimental]
interface EgressGatewayHarness
{
    public function gateway(FakeTelemetry $telemetry): EgressGateway;

    public function answer(string $url, int $status, string $body = ''): void;

    public function pointAtPrivateAddress(string $host): void;

    public function goDown(string $url): void;

    public function sentHeader(string $name): ?string;
}

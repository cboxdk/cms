<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * What the shared suite MailGatewayContract needs besides the gateway: the transport's side of it.
 * A harness for a real gateway gives it a transport that keeps the mails it takes, so no mail leaves
 * the test.
 *
 * - gateway() is the gateway under test, counting on the given telemetry.
 * - breakTransport() makes the transport take no mail from now on.
 * - delivered() lists each mail the transport took, in order, as its recipient, subject and text.
 */
#[Experimental]
interface MailGatewayHarness
{
    public function gateway(FakeTelemetry $telemetry): MailGateway;

    public function breakTransport(): void;

    /**
     * @return list<list<string>> each mail as [recipient, subject, text]
     */
    public function delivered(): array;
}

<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Testkit\Telemetry\TelemetryContract;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Telemetry suite against the application's own exporter, TeeTelemetry over two fakes.
 * The trait brings the cases; the class only says how to make the harness.
 */
final class TeeTelemetryContractTest extends TestCase
{
    use TelemetryContract;

    #[Override]
    protected function telemetry(): TelemetryHarness
    {
        return new TeeTelemetryHarness;
    }
}

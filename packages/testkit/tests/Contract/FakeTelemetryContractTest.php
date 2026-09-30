<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Telemetry\TelemetryContract;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Telemetry contract suite against the testkit's in-memory fake.
 */
final class FakeTelemetryContractTest extends TestCase
{
    use TelemetryContract;

    #[Override]
    protected function telemetry(): TelemetryHarness
    {
        return new FakeTelemetry;
    }
}

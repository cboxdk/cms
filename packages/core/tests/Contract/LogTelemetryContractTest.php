<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Core\Tests\Telemetry\LogTelemetryHarness;
use Cbox\Cms\Testkit\Telemetry\TelemetryContract;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Telemetry contract suite against LogTelemetry, the kernel's default, read back from
 * the entries it writes on the log.
 */
final class LogTelemetryContractTest extends TestCase
{
    use TelemetryContract;

    #[Override]
    protected function telemetry(): TelemetryHarness
    {
        return new LogTelemetryHarness;
    }
}

<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use PHPUnit\Framework\Assert;

/**
 * The harness of the shared suite for TeeTelemetry over two fakes: it reads what the first took,
 * and checks that the second took the same records, and interrupt() makes both refuse.
 */
final readonly class TeeTelemetryHarness implements TelemetryHarness
{
    private FakeTelemetry $first;

    private FakeTelemetry $second;

    public function __construct()
    {
        $this->first = new FakeTelemetry;
        $this->second = new FakeTelemetry;
    }

    public function telemetry(): Telemetry
    {
        return new TeeTelemetry($this->first, $this->second);
    }

    public function spans(): array
    {
        Assert::assertSame($this->first->spans(), $this->second->spans());

        return $this->first->spans();
    }

    public function counters(): array
    {
        Assert::assertSame($this->first->counters(), $this->second->counters());

        return $this->first->counters();
    }

    public function histograms(): array
    {
        Assert::assertSame($this->first->histograms(), $this->second->histograms());

        return $this->first->histograms();
    }

    public function interrupt(): void
    {
        $this->first->interrupt();
        $this->second->interrupt();
    }

    public function restore(): void
    {
        $this->first->restore();
        $this->second->restore();
    }
}

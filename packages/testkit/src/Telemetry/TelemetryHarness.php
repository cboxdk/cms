<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;

/**
 * What the shared suite TelemetryContract needs besides the exporter: the backend's side of it.
 *
 * - telemetry() is the exporter under test.
 * - spans(), counters() and histograms() list what the backend took, in the order exported, read
 *   back from the backend: a harness for a real exporter decodes what it wrote.
 * - interrupt() makes the backend refuse every record until restore().
 */
#[Experimental]
interface TelemetryHarness
{
    public function telemetry(): Telemetry;

    /**
     * @return list<SpanRecord>
     */
    public function spans(): array;

    /**
     * @return list<CounterRecord>
     */
    public function counters(): array;

    /**
     * @return list<HistogramRecord>
     */
    public function histograms(): array;

    public function interrupt(): void;

    public function restore(): void;
}

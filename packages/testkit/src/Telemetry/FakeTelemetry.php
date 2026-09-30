<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;

/**
 * The in-memory fake of Telemetry (GUARDRAILS 2.3), and its own harness. It keeps every record
 * in the order exported, and interrupt() makes it drop every record until restore(), as a
 * backend that does not take them, without throwing.
 *
 * spansNamed() gives the spans of one name, and counted() and recorded() the sum of a counter's
 * increments and the values of a histogram, so a test asks what a call exported.
 */
#[Experimental]
final class FakeTelemetry implements Telemetry, TelemetryHarness
{
    /** @var list<SpanRecord> */
    private array $spans = [];

    /** @var list<CounterRecord> */
    private array $counters = [];

    /** @var list<HistogramRecord> */
    private array $histograms = [];

    private bool $interrupted = false;

    public function telemetry(): Telemetry
    {
        return $this;
    }

    public function span(SpanRecord $span): void
    {
        if (! $this->interrupted) {
            $this->spans[] = $span;
        }
    }

    public function counter(CounterRecord $counter): void
    {
        if (! $this->interrupted) {
            $this->counters[] = $counter;
        }
    }

    public function histogram(HistogramRecord $histogram): void
    {
        if (! $this->interrupted) {
            $this->histograms[] = $histogram;
        }
    }

    public function spans(): array
    {
        return $this->spans;
    }

    public function counters(): array
    {
        return $this->counters;
    }

    public function histograms(): array
    {
        return $this->histograms;
    }

    /**
     * @return list<SpanRecord>
     */
    public function spansNamed(string $name): array
    {
        return array_values(array_filter($this->spans, static fn (SpanRecord $span): bool => $span->name->value === $name));
    }

    /**
     * The sum of the increments of the counter with this name.
     */
    public function counted(string $name): int
    {
        return array_sum(array_map(
            static fn (CounterRecord $counter): int => $counter->increment,
            array_filter($this->counters, static fn (CounterRecord $counter): bool => $counter->name->value === $name),
        ));
    }

    /**
     * The values of the histogram with this name, in the order recorded.
     *
     * @return list<float>
     */
    public function recorded(string $name): array
    {
        return array_values(array_map(
            static fn (HistogramRecord $histogram): float => $histogram->value,
            array_filter($this->histograms, static fn (HistogramRecord $histogram): bool => $histogram->name->value === $name),
        ));
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    public function restore(): void
    {
        $this->interrupted = false;
    }
}

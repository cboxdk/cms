<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;

/**
 * Exports every record to each of several exporters, in the order given, such as the kernel's log
 * exporter and an OpenTelemetry exporter while an application moves from one to the other. Each
 * exporter never throws, so neither does this one, and a backend that refuses loses only its own
 * copy.
 */
final readonly class TeeTelemetry implements Telemetry
{
    /** @var list<Telemetry> */
    private array $exporters;

    public function __construct(Telemetry ...$exporters)
    {
        $this->exporters = array_values($exporters);
    }

    public function span(SpanRecord $span): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->span($span);
        }
    }

    public function counter(CounterRecord $counter): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->counter($counter);
        }
    }

    public function histogram(HistogramRecord $histogram): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->histogram($histogram);
        }
    }
}

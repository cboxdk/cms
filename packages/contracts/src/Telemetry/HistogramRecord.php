<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One value of a histogram: its name, the value, finite and zero or more, its unit, and the
 * attributes it is recorded under, such as a call's duration in milliseconds.
 */
#[Experimental]
final readonly class HistogramRecord
{
    /**
     * @throws InvalidTelemetry when the value is negative or not finite
     */
    public function __construct(
        public TelemetryName $name,
        public float $value,
        public MetricUnit $unit,
        public Attributes $attributes = new Attributes,
    ) {
        if (! is_finite($value) || $value < 0) {
            throw InvalidTelemetry::histogramValue($name->value);
        }
    }
}

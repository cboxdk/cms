<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One increment of a counter: its name, how much it adds, 1 or more, and the attributes it is
 * counted under.
 */
#[Experimental]
final readonly class CounterRecord
{
    /**
     * @throws InvalidTelemetry when the increment is below 1
     */
    public function __construct(
        public TelemetryName $name,
        public int $increment = 1,
        public Attributes $attributes = new Attributes,
    ) {
        if ($increment < 1) {
            throw InvalidTelemetry::increment($increment);
        }
    }
}

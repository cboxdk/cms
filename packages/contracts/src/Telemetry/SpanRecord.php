<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One finished span: its name, when it started by the Clock, in UTC with microseconds, how long it
 * took in nanoseconds of real, monotonic time, how it ended, and its attributes.
 */
#[Experimental]
final readonly class SpanRecord
{
    public DateTimeImmutable $start;

    /**
     * @throws InvalidTelemetry when the duration is negative
     */
    public function __construct(
        public TelemetryName $name,
        DateTimeImmutable $start,
        public int $durationNanoseconds,
        public SpanStatus $status,
        public Attributes $attributes = new Attributes,
    ) {
        if ($durationNanoseconds < 0) {
            throw InvalidTelemetry::duration($durationNanoseconds);
        }

        $this->start = $start->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The duration in milliseconds, with the nanoseconds as its fraction.
     */
    public function durationMilliseconds(): float
    {
        return $this->durationNanoseconds / 1_000_000;
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Telemetry;

use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;

/*
 * What a test asks the telemetry fake besides the shared suite: the spans of one name, the sum of
 * a counter and the values of a histogram.
 */

it('gives the spans of one name, the sum of a counter and the values of a histogram', function (): void {
    $telemetry = new FakeTelemetry;
    $start = new DateTimeImmutable('2026-09-30 12:00:00 UTC');
    $create = new SpanRecord(new TelemetryName('entry.create'), $start, 1, SpanStatus::Ok);
    $telemetry->span($create);
    $telemetry->span(new SpanRecord(new TelemetryName('entry.revise'), $start, 2, SpanStatus::Ok));
    $telemetry->counter(new CounterRecord(new TelemetryName('cms.command.errors'), 2));
    $telemetry->counter(new CounterRecord(new TelemetryName('cms.query.errors'), 5));
    $telemetry->counter(new CounterRecord(new TelemetryName('cms.command.errors'), 3));
    $telemetry->histogram(new HistogramRecord(new TelemetryName('cms.command.duration'), 4.5, MetricUnit::Milliseconds));
    $telemetry->histogram(new HistogramRecord(new TelemetryName('cms.query.duration'), 9.0, MetricUnit::Milliseconds));
    $telemetry->histogram(new HistogramRecord(new TelemetryName('cms.command.duration'), 1.25, MetricUnit::Milliseconds));

    expect($telemetry->spansNamed('entry.create'))->toBe([$create])
        ->and($telemetry->spansNamed('entry.publish'))->toBe([])
        ->and($telemetry->counted('cms.command.errors'))->toBe(5)
        ->and($telemetry->counted('cms.command.calls'))->toBe(0)
        ->and($telemetry->recorded('cms.command.duration'))->toBe([4.5, 1.25])
        ->and($telemetry->recorded('cms.command.size'))->toBe([])
        ->and($telemetry->telemetry())->toBe($telemetry);
});

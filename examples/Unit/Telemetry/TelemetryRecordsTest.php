<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\InvalidTelemetry;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

// An addon's search indexer exports a span and metrics for one batch through the Telemetry
// contract, and its test reads them back from the testkit's fake. The attributes hold ids, codes
// and counts, never a field's value.

it('exports a batch as a span with its metrics, which the fake gives back', function (): void {
    $telemetry = new FakeTelemetry;
    $attributes = new Attributes(Attribute::of('acme.search.index', 'articles'), Attribute::of('acme.search.documents', 20));

    $telemetry->span(new SpanRecord(new TelemetryName('acme.search.batch'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), 42_000_000, SpanStatus::Ok, $attributes));
    $telemetry->histogram(new HistogramRecord(new TelemetryName('acme.search.batch.duration'), 42.0, MetricUnit::Milliseconds, $attributes));
    $telemetry->counter(new CounterRecord(new TelemetryName('acme.search.documents'), 20, $attributes));

    expect($telemetry->spansNamed('acme.search.batch'))->toHaveCount(1)
        ->and($telemetry->spansNamed('acme.search.batch')[0]->durationMilliseconds())->toBe(42.0)
        ->and($telemetry->spans()[0]->attributes->get('acme.search.index'))->toBe('articles')
        ->and($telemetry->recorded('acme.search.batch.duration'))->toBe([42.0])
        ->and($telemetry->counted('acme.search.documents'))->toBe(20);
});

it('refuses a name that is not dotted lowercase and an attribute given twice', function (): void {
    expect(fn (): TelemetryName => new TelemetryName('Acme Search'))->toThrow(InvalidTelemetry::class)
        ->and(fn (): Attributes => new Attributes(Attribute::of('acme.search.index', 'a'), Attribute::of('acme.search.index', 'b')))->toThrow(InvalidTelemetry::class);
});

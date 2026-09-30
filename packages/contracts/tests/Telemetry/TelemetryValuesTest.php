<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Telemetry;

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\InvalidTelemetry;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use DateTimeImmutable;

/*
 * The values of the Telemetry contract (GUARDRAILS 2.3, 5): names, attributes and the three
 * records.
 */

it('takes names of dotted lowercase segments and refuses every other form', function (string $name, bool $valid): void {
    $make = static fn (): TelemetryName => new TelemetryName($name);

    if ($valid) {
        expect($make()->value)->toBe($name);
    } else {
        expect($make)->toThrow(InvalidTelemetry::class, sprintf('The telemetry name "%s" is not in the form', $name));
    }
})->with([
    ['cms.action', true],
    ['entry.create', true],
    ['cms.command.duration', true],
    ['cms', true],
    ['cms.changeset_id', true],
    ['a2.b_3', true],
    ['', false],
    ['Cms.action', false],
    ['cms..action', false],
    ['cms.action.', false],
    ['.cms', false],
    ['cms.1action', false],
    ['cms action', false],
    ['cms-action', false],
    ['_cms', false],
]);

it('refuses a name longer than the limit and takes one at it', function (): void {
    $at = str_repeat('a', TelemetryName::MAX_LENGTH);

    expect(new TelemetryName($at)->value)->toBe($at)
        ->and(fn (): TelemetryName => new TelemetryName($at.'a'))->toThrow(InvalidTelemetry::class, 'at most 255 characters');
});

it('compares names by value', function (): void {
    expect(new TelemetryName('cms.action')->equals(new TelemetryName('cms.action')))->toBeTrue()
        ->and(new TelemetryName('cms.action')->equals(new TelemetryName('cms.outcome')))->toBeFalse();
});

it('takes scalar attributes and refuses text that is not UTF-8 or too long and numbers that are not finite', function (): void {
    $limit = str_repeat('x', Attribute::MAX_TEXT_BYTES);

    expect(Attribute::of('cms.text', $limit)->value)->toBe($limit)
        ->and(Attribute::of('cms.count', 3)->value)->toBe(3)
        ->and(Attribute::of('cms.ratio', 0.5)->value)->toBe(0.5)
        ->and(Attribute::of('cms.flag', true)->value)->toBeTrue()
        ->and(Attribute::of('cms.text', 'æøå')->value)->toBe('æøå')
        ->and(fn (): Attribute => Attribute::of('cms.text', $limit.'x'))->toThrow(InvalidTelemetry::class, 'The attribute "cms.text" holds text that is not valid UTF-8 or is longer than 1024 bytes.')
        ->and(fn (): Attribute => Attribute::of('cms.text', "\xC3\x28"))->toThrow(InvalidTelemetry::class, 'not valid UTF-8')
        ->and(fn (): Attribute => Attribute::of('cms.ratio', INF))->toThrow(InvalidTelemetry::class, 'The attribute "cms.ratio" holds a number that is not finite.')
        ->and(fn (): Attribute => Attribute::of('cms.ratio', NAN))->toThrow(InvalidTelemetry::class, 'not finite')
        ->and(fn (): Attribute => Attribute::of('Cms', 'x'))->toThrow(InvalidTelemetry::class, 'The telemetry name "Cms"');
});

it('sorts attributes by name, reads them by name and refuses a name given twice', function (): void {
    $attributes = new Attributes(Attribute::of('cms.outcome', 'committed'), Attribute::of('cms.action', 'entry.create'), Attribute::of('cms.action.version', 1));

    expect($attributes->names())->toBe(['cms.action', 'cms.action.version', 'cms.outcome'])
        ->and($attributes->get('cms.outcome'))->toBe('committed')
        ->and($attributes->get('cms.action.version'))->toBe(1)
        ->and($attributes->get('cms.changeset_id'))->toBeNull()
        ->and(new Attributes()->attributes)->toBe([])
        ->and(fn (): Attributes => new Attributes(Attribute::of('cms.action', 'a'), Attribute::of('cms.action', 'b')))
        ->toThrow(InvalidTelemetry::class, 'The attribute "cms.action" is given twice; each name holds one value.');
});

it('compares attributes by name, value and type', function (): void {
    $one = new Attributes(Attribute::of('cms.count', 1), Attribute::of('cms.action', 'a'));

    expect($one->equals(new Attributes(Attribute::of('cms.action', 'a'), Attribute::of('cms.count', 1))))->toBeTrue()
        ->and($one->equals(new Attributes(Attribute::of('cms.action', 'a'), Attribute::of('cms.count', '1'))))->toBeFalse()
        ->and($one->equals(new Attributes(Attribute::of('cms.action', 'a'))))->toBeFalse()
        ->and($one->equals(new Attributes(Attribute::of('cms.action', 'a'), Attribute::of('cms.total', 1))))->toBeFalse();
});

it('holds a span in UTC with its duration, and refuses a negative duration', function (): void {
    $span = new SpanRecord(new TelemetryName('entry.create'), new DateTimeImmutable('2026-09-30 14:00:00.250000+02:00'), 1_500_000, SpanStatus::Ok);

    expect($span->start->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-30T12:00:00.250000+00:00')
        ->and($span->durationNanoseconds)->toBe(1_500_000)
        ->and($span->durationMilliseconds())->toBe(1.5)
        ->and(new SpanRecord(new TelemetryName('entry.create'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), 0, SpanStatus::Ok)->durationMilliseconds())->toBe(0.0)
        ->and($span->attributes->attributes)->toBe([])
        ->and(fn (): SpanRecord => new SpanRecord(new TelemetryName('entry.create'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), -1, SpanStatus::Error))
        ->toThrow(InvalidTelemetry::class, 'A span lasts zero nanoseconds or more, got -1.');
});

it('counts by 1 or more and refuses less', function (): void {
    expect(new CounterRecord(new TelemetryName('cms.command.errors'))->increment)->toBe(1)
        ->and(new CounterRecord(new TelemetryName('cms.command.errors'), 4)->increment)->toBe(4)
        ->and(fn (): CounterRecord => new CounterRecord(new TelemetryName('cms.command.errors'), 0))
        ->toThrow(InvalidTelemetry::class, 'A counter is incremented by 1 or more, got 0.');
});

it('records finite histogram values of zero or more and refuses others', function (float $value): void {
    expect(fn (): HistogramRecord => new HistogramRecord(new TelemetryName('cms.command.duration'), $value, MetricUnit::Milliseconds))
        ->toThrow(InvalidTelemetry::class, 'The histogram "cms.command.duration" records a finite value of zero or more.');
})->with([-0.001, INF, NAN]);

it('keeps a histogram value and its unit', function (): void {
    $histogram = new HistogramRecord(new TelemetryName('cms.command.duration'), 0.0, MetricUnit::Milliseconds);

    expect($histogram->value)->toBe(0.0)
        ->and($histogram->unit->value)->toBe('ms')
        ->and(MetricUnit::Bytes->value)->toBe('By')
        ->and(MetricUnit::One->value)->toBe('1')
        ->and(SpanStatus::Error->value)->toBe('error');
});

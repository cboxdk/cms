<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The shared contract suite for Telemetry (GUARDRAILS 2.3 and 9). The fake and every real
 * exporter run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new exporter whose backend has taken nothing yet:
 *
 *     final class FakeTelemetryContractTest extends TestCase
 *     {
 *         use TelemetryContract;
 *
 *         protected function telemetry(): TelemetryHarness
 *         {
 *             return new FakeTelemetry;
 *         }
 *     }
 *
 * The cases cover a span with its name, start to the microsecond, duration to the nanosecond,
 * status and attributes of every scalar type; spans, counters and histograms in the order
 * exported; and a backend that refuses, which never makes an export throw.
 */
#[Experimental]
trait TelemetryContract
{
    /**
     * A harness for a new exporter whose backend has taken nothing.
     */
    abstract protected function telemetry(): TelemetryHarness;

    #[Test]
    public function a_span_is_exported_with_its_name_start_duration_status_and_attributes(): void
    {
        $harness = $this->telemetry();
        $span = new SpanRecord(
            new TelemetryName('entry.create'),
            new DateTimeImmutable('2026-09-30 12:34:56.123456 UTC'),
            1_234_567,
            SpanStatus::Ok,
            $this->attributes(),
        );

        $harness->telemetry()->span($span);

        Assert::assertCount(1, $harness->spans());
        $this->assertSameSpan($span, $harness->spans()[0]);
        Assert::assertSame([], $harness->counters());
        Assert::assertSame([], $harness->histograms());
    }

    #[Test]
    public function spans_are_exported_in_order_with_their_status(): void
    {
        $harness = $this->telemetry();
        $first = new SpanRecord(new TelemetryName('entry.create'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), 0, SpanStatus::Error);
        $second = new SpanRecord(new TelemetryName('entry.revise'), new DateTimeImmutable('2026-09-30 11:59:59.999999 UTC'), 5, SpanStatus::Ok);

        $harness->telemetry()->span($first);
        $harness->telemetry()->span($second);

        Assert::assertCount(2, $harness->spans());
        $this->assertSameSpan($first, $harness->spans()[0]);
        $this->assertSameSpan($second, $harness->spans()[1]);
    }

    #[Test]
    public function counters_are_exported_in_order_with_their_increments_and_attributes(): void
    {
        $harness = $this->telemetry();
        $one = new CounterRecord(new TelemetryName('cms.command.errors'), 1, $this->attributes());
        $three = new CounterRecord(new TelemetryName('cms.command.errors'), 3);

        $harness->telemetry()->counter($one);
        $harness->telemetry()->counter($three);

        Assert::assertCount(2, $harness->counters());

        foreach ([$one, $three] as $index => $expected) {
            $counter = $harness->counters()[$index];
            Assert::assertSame($expected->name->value, $counter->name->value);
            Assert::assertSame($expected->increment, $counter->increment);
            $this->assertSameAttributes($expected->attributes, $counter->attributes);
        }

        Assert::assertSame([], $harness->spans());
    }

    #[Test]
    public function histogram_values_are_exported_in_order_with_their_unit_and_attributes(): void
    {
        $harness = $this->telemetry();
        $duration = new HistogramRecord(new TelemetryName('cms.command.duration'), 12.5, MetricUnit::Milliseconds, $this->attributes());
        $size = new HistogramRecord(new TelemetryName('cms.fragment.size'), 0.0, MetricUnit::Bytes);

        $harness->telemetry()->histogram($duration);
        $harness->telemetry()->histogram($size);

        Assert::assertCount(2, $harness->histograms());

        foreach ([$duration, $size] as $index => $expected) {
            $histogram = $harness->histograms()[$index];
            Assert::assertSame($expected->name->value, $histogram->name->value);
            Assert::assertSame($expected->value, $histogram->value);
            Assert::assertSame($expected->unit, $histogram->unit);
            $this->assertSameAttributes($expected->attributes, $histogram->attributes);
        }
    }

    #[Test]
    public function a_backend_that_refuses_never_makes_an_export_throw_and_takes_records_once_it_is_back(): void
    {
        $harness = $this->telemetry();
        $span = new SpanRecord(new TelemetryName('entry.create'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), 10, SpanStatus::Ok);
        $counter = new CounterRecord(new TelemetryName('cms.command.errors'));
        $histogram = new HistogramRecord(new TelemetryName('cms.command.duration'), 1.0, MetricUnit::Milliseconds);
        $harness->interrupt();

        try {
            $harness->telemetry()->span($span);
            $harness->telemetry()->counter($counter);
            $harness->telemetry()->histogram($histogram);
        } catch (Throwable $thrown) {
            Assert::fail(sprintf('An export threw %s while the backend refused: %s', $thrown::class, $thrown->getMessage()));
        }

        Assert::assertSame([], $harness->spans());
        Assert::assertSame([], $harness->counters());
        Assert::assertSame([], $harness->histograms());

        $harness->restore();
        $harness->telemetry()->span($span);
        $harness->telemetry()->counter($counter);
        $harness->telemetry()->histogram($histogram);

        Assert::assertCount(1, $harness->spans());
        $this->assertSameSpan($span, $harness->spans()[0]);
        Assert::assertCount(1, $harness->counters());
        Assert::assertCount(1, $harness->histograms());
    }

    /**
     * Attributes of every scalar type, given out of order.
     */
    protected function attributes(): Attributes
    {
        return new Attributes(
            Attribute::of('cms.outcome', 'committed'),
            Attribute::of('cms.action.version', 2),
            Attribute::of('cms.dry_run', false),
            Attribute::of('cms.action', 'entry.create'),
            Attribute::of('cms.ratio', 0.25),
            Attribute::of('cms.changeset_id', '01960000-0000-7000-8000-00000000000a'),
        );
    }

    protected function assertSameSpan(SpanRecord $expected, SpanRecord $actual): void
    {
        Assert::assertSame($expected->name->value, $actual->name->value);
        Assert::assertSame($expected->start->format('Y-m-d\TH:i:s.uP'), $actual->start->format('Y-m-d\TH:i:s.uP'));
        Assert::assertSame($expected->durationNanoseconds, $actual->durationNanoseconds);
        Assert::assertSame($expected->status, $actual->status);
        $this->assertSameAttributes($expected->attributes, $actual->attributes);
    }

    protected function assertSameAttributes(Attributes $expected, Attributes $actual): void
    {
        Assert::assertSame($expected->names(), $actual->names());

        foreach ($expected->attributes as $index => $attribute) {
            Assert::assertSame($attribute->value, $actual->attributes[$index]->value, sprintf('The attribute "%s" differs.', $attribute->name->value));
        }
    }
}

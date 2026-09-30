<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Telemetry;

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Core\Telemetry\Adapter\LogTelemetry;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use PHPUnit\Framework\Assert;
use Psr\Log\LogLevel;

/**
 * The harness of the shared Telemetry suite for LogTelemetry: the exporter writes to a recording
 * logger, and the harness decodes each entry of the log back into the record it holds, checking
 * its level, message and the types of its context. interrupt() makes the logger throw for every
 * entry, as a failing log channel.
 */
final readonly class LogTelemetryHarness implements TelemetryHarness
{
    private RefusingLogger $logger;

    private LogTelemetry $telemetry;

    public function __construct()
    {
        $this->logger = new RefusingLogger;
        $this->telemetry = new LogTelemetry($this->logger);
    }

    #[Override]
    public function telemetry(): Telemetry
    {
        return $this->telemetry;
    }

    #[Override]
    public function spans(): array
    {
        $spans = [];

        foreach ($this->entries(LogTelemetry::SPAN) as [$level, $context]) {
            Assert::assertIsString($context['span']);
            Assert::assertIsString($context['start']);
            Assert::assertIsInt($context['duration_ns']);
            Assert::assertIsFloat($context['duration_ms']);
            Assert::assertIsString($context['status']);
            $status = SpanStatus::from($context['status']);
            Assert::assertSame($status === SpanStatus::Error ? LogLevel::WARNING : LogLevel::INFO, $level);
            Assert::assertSame((float) ($context['duration_ns'] / 1_000_000), $context['duration_ms']);
            $start = DateTimeImmutable::createFromFormat(LogTelemetry::START_FORMAT, $context['start'], new DateTimeZone('UTC'));
            Assert::assertInstanceOf(DateTimeImmutable::class, $start);

            $spans[] = new SpanRecord(new TelemetryName($context['span']), $start, $context['duration_ns'], $status, $this->attributes($context['attributes'] ?? null));
        }

        return $spans;
    }

    #[Override]
    public function counters(): array
    {
        $counters = [];

        foreach ($this->entries(LogTelemetry::COUNTER) as [$level, $context]) {
            Assert::assertSame(LogLevel::INFO, $level);
            Assert::assertIsString($context['counter']);
            Assert::assertIsInt($context['increment']);

            $counters[] = new CounterRecord(new TelemetryName($context['counter']), $context['increment'], $this->attributes($context['attributes'] ?? null));
        }

        return $counters;
    }

    #[Override]
    public function histograms(): array
    {
        $histograms = [];

        foreach ($this->entries(LogTelemetry::HISTOGRAM) as [$level, $context]) {
            Assert::assertSame(LogLevel::INFO, $level);
            Assert::assertIsString($context['histogram']);
            Assert::assertIsFloat($context['value']);
            Assert::assertIsString($context['unit']);

            $histograms[] = new HistogramRecord(new TelemetryName($context['histogram']), $context['value'], MetricUnit::from($context['unit']), $this->attributes($context['attributes'] ?? null));
        }

        return $histograms;
    }

    #[Override]
    public function interrupt(): void
    {
        $this->logger->refuses = true;
    }

    #[Override]
    public function restore(): void
    {
        $this->logger->refuses = false;
    }

    /**
     * The level and context of each entry with this message, in the order logged.
     *
     * @return list<array{string, array<array-key, mixed>}>
     */
    private function entries(string $message): array
    {
        $entries = [];

        foreach ($this->logger->records as [$level, $logged, $context]) {
            Assert::assertContains($logged, [LogTelemetry::SPAN, LogTelemetry::COUNTER, LogTelemetry::HISTOGRAM]);

            if ($logged === $message) {
                $entries[] = [$level, $context];
            }
        }

        return $entries;
    }

    private function attributes(mixed $logged): Attributes
    {
        Assert::assertIsArray($logged);
        $attributes = [];

        foreach ($logged as $name => $value) {
            Assert::assertIsString($name);
            Assert::assertTrue(is_string($value) || is_int($value) || is_float($value) || is_bool($value), sprintf('The attribute "%s" is not a scalar.', $name));
            $attributes[] = Attribute::of($name, $value);
        }

        Assert::assertSame(array_keys($logged), new Attributes(...$attributes)->names(), 'The attributes are logged sorted by name.');

        return new Attributes(...$attributes);
    }
}

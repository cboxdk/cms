<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Telemetry\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Telemetry exported as structured records on the application's log, through Laravel's logging
 * (GUARDRAILS 2.3, 5): the kernel's default, bound in cbox-cms.contracts. Each record is one log
 * entry whose message names its kind and whose context holds the record, so a log pipeline reads
 * it as structured data:
 *
 * - SPAN (`cms.span`): `span`, `start` (UTC with microseconds), `duration_ns`, `duration_ms`,
 *   `status` and `attributes`, at info, or at warning when the status is error.
 * - COUNTER (`cms.counter`): `counter`, `increment` and `attributes`, at info.
 * - HISTOGRAM (`cms.histogram`): `histogram`, `value`, `unit` and `attributes`, at info.
 *
 * `attributes` maps each attribute's name to its value, sorted by name. A pipeline's span carries
 * the changeset and the correlation id, so its entry is the call's structured log. A logger that
 * throws drops the record: an export never throws. An application that exports to OpenTelemetry
 * binds its own implementation, such as one on laravel-telemetry in the app template.
 */
#[Experimental]
final readonly class LogTelemetry implements Telemetry
{
    public const string SPAN = 'cms.span';

    public const string COUNTER = 'cms.counter';

    public const string HISTOGRAM = 'cms.histogram';

    /** The form of a span's start in its entry. */
    public const string START_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(private LoggerInterface $logger) {}

    #[Override]
    public function span(SpanRecord $span): void
    {
        $context = [
            'span' => $span->name->value,
            'start' => $span->start->format(self::START_FORMAT),
            'duration_ns' => $span->durationNanoseconds,
            'duration_ms' => $span->durationMilliseconds(),
            'status' => $span->status->value,
            'attributes' => $this->attributes($span->attributes),
        ];

        $this->write(fn () => $span->status === SpanStatus::Error
            ? $this->logger->warning(self::SPAN, $context)
            : $this->logger->info(self::SPAN, $context));
    }

    #[Override]
    public function counter(CounterRecord $counter): void
    {
        $this->write(fn () => $this->logger->info(self::COUNTER, [
            'counter' => $counter->name->value,
            'increment' => $counter->increment,
            'attributes' => $this->attributes($counter->attributes),
        ]));
    }

    #[Override]
    public function histogram(HistogramRecord $histogram): void
    {
        $this->write(fn () => $this->logger->info(self::HISTOGRAM, [
            'histogram' => $histogram->name->value,
            'value' => $histogram->value,
            'unit' => $histogram->unit->value,
            'attributes' => $this->attributes($histogram->attributes),
        ]));
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function attributes(Attributes $attributes): array
    {
        $values = [];

        foreach ($attributes->attributes as $attribute) {
            $values[$attribute->name->value] = $attribute->value;
        }

        return $values;
    }

    /**
     * Writes the entry, and drops it when the logger throws.
     *
     * @param  callable(): void  $entry
     */
    private function write(callable $entry): void
    {
        try {
            $entry();
        } catch (Throwable) {
            return;
        }
    }
}

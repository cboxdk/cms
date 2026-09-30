<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One attribute of a span or a metric: a name and a scalar value. Text is valid UTF-8 of at most
 * MAX_TEXT_BYTES bytes, and a float is finite. An attribute holds an id, a name, a code, a count
 * or a flag, never the value of a field classified above public (invariant 10), a secret or
 * personal data (GUARDRAILS 5, 6).
 */
#[Experimental]
final readonly class Attribute
{
    public const int MAX_TEXT_BYTES = 1024;

    /**
     * @throws InvalidTelemetry when the text or the number is not in its form
     */
    public function __construct(
        public TelemetryName $name,
        public string|int|float|bool $value,
    ) {
        if (is_string($value) && (strlen($value) > self::MAX_TEXT_BYTES || preg_match('/\A/u', $value) !== 1)) {
            throw InvalidTelemetry::attributeText($name->value);
        }

        if (is_float($value) && ! is_finite($value)) {
            throw InvalidTelemetry::attributeNumber($name->value);
        }
    }

    /**
     * @throws InvalidTelemetry when the name, the text or the number is not in its form
     */
    public static function of(string $name, string|int|float|bool $value): self
    {
        return new self(new TelemetryName($name), $value);
    }
}

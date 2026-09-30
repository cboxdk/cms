<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a span, a metric or an attribute: lowercase segments of letters, digits and
 * underscores, each starting with a letter and separated by dots, such as `cms.command.duration`
 * or `cms.changeset_id`, at most MAX_LENGTH characters. The kernel's own names start with `cms.`
 * (PRD 16.6), and a span is named after its action, such as `entry.create`.
 */
#[Experimental]
final readonly class TelemetryName
{
    public const string PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/';

    public const int MAX_LENGTH = 255;

    /**
     * @throws InvalidTelemetry when the name is not in the form
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidTelemetry::name($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

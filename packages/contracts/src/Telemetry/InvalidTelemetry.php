<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A telemetry name, attribute or record that is not in its form.
 */
#[Experimental]
final class InvalidTelemetry extends InvalidArgumentException
{
    public static function name(string $name): self
    {
        return new self(sprintf(
            'The telemetry name "%s" is not in the form lowercase segments of letters, digits and underscores, each starting with a letter and separated by dots, at most %d characters, such as "cms.action".',
            $name,
            TelemetryName::MAX_LENGTH,
        ));
    }

    public static function attributeText(string $name): self
    {
        return new self(sprintf(
            'The attribute "%s" holds text that is not valid UTF-8 or is longer than %d bytes.',
            $name,
            Attribute::MAX_TEXT_BYTES,
        ));
    }

    public static function attributeNumber(string $name): self
    {
        return new self(sprintf('The attribute "%s" holds a number that is not finite.', $name));
    }

    public static function duplicateAttribute(string $name): self
    {
        return new self(sprintf('The attribute "%s" is given twice; each name holds one value.', $name));
    }

    public static function duration(int $nanoseconds): self
    {
        return new self(sprintf('A span lasts zero nanoseconds or more, got %d.', $nanoseconds));
    }

    public static function increment(int $increment): self
    {
        return new self(sprintf('A counter is incremented by 1 or more, got %d.', $increment));
    }

    public static function histogramValue(string $name): self
    {
        return new self(sprintf('The histogram "%s" records a finite value of zero or more.', $name));
    }
}

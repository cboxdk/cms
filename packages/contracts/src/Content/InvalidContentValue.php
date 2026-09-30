<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A locale, a slug, a variant key, a revision number or a time window that breaks its invariants.
 */
#[Experimental]
final class InvalidContentValue extends InvalidArgumentException
{
    public static function locale(string $value): self
    {
        return new self(sprintf(
            'A locale is a BCP 47 tag of a language, an optional script and an optional region, such as "da", "en-GB" or "sr-Latn", got "%s".',
            self::shown($value),
        ));
    }

    public static function slug(string $value): self
    {
        return new self(sprintf(
            'A slug is 1 to %d characters without a slash or white space, and not "." or "..", got "%s".',
            Slug::MAX_LENGTH,
            self::shown($value),
        ));
    }

    public static function revisionNumber(int $value): self
    {
        return new self(sprintf('A revision number starts at 1, got %d.', $value));
    }

    public static function timeWindow(DateTimeImmutable $from, DateTimeImmutable $until): self
    {
        return new self(sprintf(
            'A time window starts before it ends, got from %s until %s.',
            $from->format(DateTimeInterface::RFC3339_EXTENDED),
            $until->format(DateTimeInterface::RFC3339_EXTENDED),
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}

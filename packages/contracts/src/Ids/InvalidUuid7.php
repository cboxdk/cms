<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A string that is not a UUIDv7, or a time that a UUIDv7 cannot hold.
 */
#[Experimental]
final class InvalidUuid7 extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "%s".',
            self::shown($value),
        ));
    }

    public static function wrongVersion(string $value): self
    {
        return new self(sprintf('"%s" is a UUID version %s, not version 7.', $value, $value[14]));
    }

    public static function wrongVariant(string $value): self
    {
        return new self(sprintf(
            '"%s" does not have the RFC 9562 variant: the 17th hex digit must be 8, 9, a or b.',
            $value,
        ));
    }

    public static function timeOutOfRange(int $unixMilliseconds): self
    {
        return new self(sprintf(
            'A UUIDv7 holds a unix time from 0 to %d milliseconds, got %d.',
            Uuid7::MAX_UNIX_MILLISECONDS,
            $unixMilliseconds,
        ));
    }

    public static function exhausted(Uuid7 $after): self
    {
        return new self(sprintf('There is no UUIDv7 after "%s".', $after->value));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}

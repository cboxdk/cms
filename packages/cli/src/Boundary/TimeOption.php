<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Reads an instant from a command option: a date (`2031-05-01`, midnight UTC) or an ISO 8601
 * date and time with an offset (`2031-05-01T12:30:00+02:00`, fractions allowed). The result is
 * in UTC.
 */
#[Internal]
final readonly class TimeOption
{
    private const array FORMATS = ['!Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    public static function parse(string $option, mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::invalid($option, get_debug_type($value));
        }

        $utc = new DateTimeZone('UTC');

        foreach (self::FORMATS as $format) {
            $instant = DateTimeImmutable::createFromFormat($format, $value, $utc);

            if ($instant !== false && DateTimeImmutable::getLastErrors() === false) {
                return $instant->setTimezone($utc);
            }
        }

        throw self::invalid($option, $value);
    }

    private static function invalid(string $option, string $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The option --%s is "%s". Give a date such as 2031-05-01, or a date and time with an offset such as 2031-05-01T12:30:00+00:00.',
            $option,
            $value,
        ));
    }
}

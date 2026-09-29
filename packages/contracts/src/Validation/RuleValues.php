<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How the rules read the values they compare, shared by FieldRules, which checks a rule's
 * arguments, and the kernel's input validator, which checks a value against them: whole numbers,
 * dates and date-times, each written as text. DecimalNumber reads decimal numbers.
 */
#[Internal]
final readonly class RuleValues
{
    private const string INTEGER = '/\A-?(?:0|[1-9][0-9]*)\z/';

    private const string DATETIME = '/\A(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2})[Tt](?<hour>[0-9]{2}):(?<minute>[0-9]{2}):(?<second>[0-9]{2})(?<fraction>\.[0-9]{1,6})?(?<offset>[Zz]|[+-](?<offsetHour>[0-9]{2}):(?<offsetMinute>[0-9]{2}))\z/';

    /** The largest offset from UTC that Postgres accepts in a timestamptz, in hours. */
    private const int MAX_OFFSET_HOURS = 15;

    /**
     * The whole number $value writes in canonical form, such as `-12`, or null when it writes
     * another number or none, or one a PHP int cannot hold.
     */
    public static function integer(string $value): ?int
    {
        if (preg_match(self::INTEGER, $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return (string) $number === $value ? $number : null;
    }

    /**
     * Whether $value is a real date written `YYYY-MM-DD`, from year 0001.
     */
    public static function date(string $value): bool
    {
        try {
            new DateValue($value);
        } catch (InvalidFieldValue) {
            return false;
        }

        return true;
    }

    /**
     * The instant $value writes as a date-time of RFC 3339 with its offset, in UTC, or null when it
     * writes none. Only what Postgres stores unchanged in a timestamptz counts: a real date from
     * year 0001, at most 6 digits after the second's point, and an offset of at most 15:59, as
     * Postgres refuses larger ones. A leap second is not a time Postgres stores.
     */
    public static function datetime(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::DATETIME, $value, $parts) !== 1
            || ! self::date($parts['date'])
            || (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) $parts['second'] > 59) {
            return null;
        }

        $offset = strtoupper($parts['offset']);

        if ($offset !== 'Z' && ((int) ($parts['offsetHour'] ?? '') > self::MAX_OFFSET_HOURS || (int) ($parts['offsetMinute'] ?? '') > 59)) {
            return null;
        }

        $instant = new DateTimeImmutable(sprintf('%sT%s:%s:%s%s%s', $parts['date'], $parts['hour'], $parts['minute'], $parts['second'], $parts['fraction'], $offset));

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}

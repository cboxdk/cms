<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use LogicException;

/**
 * The sort key of a relation's released edge (PRD 5.11): the value of the relation's sort_by field
 * as text whose order in the C collation, byte by byte, is the order of the values, so
 * `relations.sort_key` is one text column for every field type that sorts, and its comparisons are
 * leakproof, which lets Postgres seek an index on it below the table's row level security:
 *
 * - text and a select's option as themselves, in the order of their bytes;
 * - a day as `YYYY-MM-DD` and an instant in UTC as `YYYY-MM-DDTHH:MM:SS.uuuuuuZ`;
 * - false as `0` and true as `1`;
 * - an integer as `1` and its 19 digits when it is not negative, and as `0` and the 19 digits of
 *   the integer plus 2^63 when it is;
 * - a decimal as `2` for zero, `3`, the number of digits before the point in two digits and the
 *   digits without the point for a positive one, and `1`, 99 less that number in two digits, each
 *   digit d as 9 - d and a `~` for a negative one, so a longer magnitude sorts first among the
 *   negatives and last among the positives.
 *
 * Null has no sort key and sorts as the greatest value, as in the type tables.
 */
#[Internal]
final readonly class RelationSortKey
{
    public static function of(FieldValue $value): ?string
    {
        return match (true) {
            $value instanceof NullValue => null,
            $value instanceof TextValue, $value instanceof DateValue => $value->value,
            $value instanceof DateTimeValue => $value->value->format('Y-m-d\TH:i:s.u\Z'),
            $value instanceof BooleanValue => $value->value ? '1' : '0',
            $value instanceof IntegerValue => $value->value < 0
                ? '0'.sprintf('%019d', $value->value + PHP_INT_MAX + 1)
                : '1'.sprintf('%019d', $value->value),
            $value instanceof DecimalValue => self::decimal($value->value),
            default => throw new LogicException(sprintf('The field value %s has no sort key.', $value::class)),
        };
    }

    /**
     * @param  string  $value  a decimal in canonical form, as DecimalValue holds it
     */
    private static function decimal(string $value): string
    {
        if ($value === '0') {
            return '2';
        }

        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $wholeDigits = $whole === '0' ? 0 : strlen($whole);
        $digits = ($whole === '0' ? '' : $whole).$fraction;

        if (! $negative) {
            return '3'.sprintf('%02d', $wholeDigits).$digits;
        }

        return '1'.sprintf('%02d', 99 - $wholeDigits).strtr($digits, '0123456789', '9876543210').'~';
    }
}

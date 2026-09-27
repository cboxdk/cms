<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Compares the `min` and `max` of a field, each already a value object that checked its form: a
 * decimal number, a date and a time. Each comparison returns less than, equal to or greater than
 * zero like the spaceship operator.
 */
#[Internal]
final readonly class Bounds
{
    /**
     * Two decimal numbers compared by value without rounding, whatever their digits: `1.50` equals
     * `1.5`, `-0` equals `0`, and `10` is greater than `9.999999999999999999`.
     */
    public static function compareDecimals(DecimalBound $a, DecimalBound $b): int
    {
        $signA = self::sign($a->value);
        $signB = self::sign($b->value);

        if ($signA !== $signB || $signA === 0) {
            return $signA <=> $signB;
        }

        return $signA * self::compareMagnitudes(ltrim($a->value, '-'), ltrim($b->value, '-'));
    }

    /**
     * Two dates compared by day.
     */
    public static function compareDates(BlueprintDate $a, BlueprintDate $b): int
    {
        return self::order(strcmp($a->value, $b->value));
    }

    /**
     * Two times compared as instants, whatever their offsets, to every digit of the fraction.
     */
    public static function compareDatetimes(BlueprintDatetime $a, BlueprintDatetime $b): int
    {
        if ($a->seconds !== $b->seconds) {
            return $a->seconds <=> $b->seconds;
        }

        $length = max(strlen($a->fraction), strlen($b->fraction));

        return self::order(strcmp(str_pad($a->fraction, $length, '0'), str_pad($b->fraction, $length, '0')));
    }

    /**
     * -1, 0 or 1 for a decimal number of DecimalBound::PATTERN.
     */
    private static function sign(string $decimal): int
    {
        if (trim($decimal, '-0.') === '') {
            return 0;
        }

        return str_starts_with($decimal, '-') ? -1 : 1;
    }

    /**
     * Two decimal numbers without a sign, compared by value.
     */
    private static function compareMagnitudes(string $a, string $b): int
    {
        $integerA = ltrim(explode('.', $a)[0], '0');
        $integerB = ltrim(explode('.', $b)[0], '0');

        if (strlen($integerA) !== strlen($integerB)) {
            return strlen($integerA) <=> strlen($integerB);
        }

        if ($integerA !== $integerB) {
            return self::order(strcmp($integerA, $integerB));
        }

        $fractionA = explode('.', $a)[1] ?? '';
        $fractionB = explode('.', $b)[1] ?? '';
        $length = max(strlen($fractionA), strlen($fractionB));

        return self::order(strcmp(str_pad($fractionA, $length, '0'), str_pad($fractionB, $length, '0')));
    }

    /**
     * -1, 0 or 1 for the result of strcmp(). Digits are compared as strings, never as PHP numbers,
     * which would round a long number to a float.
     */
    private static function order(int $comparison): int
    {
        return $comparison <=> 0;
    }
}

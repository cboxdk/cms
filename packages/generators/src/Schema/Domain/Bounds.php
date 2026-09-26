<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Compares the `min` and `max` of a field in the forms the blueprint schema v1 gives them: a
 * decimal number as a string, a date as `YYYY-MM-DD` and a time in RFC 3339. Each comparison
 * returns less than, equal to or greater than zero like the spaceship operator, or null when a
 * value is not of its form, which the blueprint schema has already reported.
 */
#[Internal]
final readonly class Bounds
{
    /** The pattern of `min` and `max` of a decimal field in blueprint.v1.json. */
    public const string DECIMAL = '/\A-?[0-9]+(?:\.[0-9]+)?\z/';

    /** A full date of RFC 3339. */
    public const string DATE = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/';

    /** A date-time of RFC 3339, with the fraction of a second apart so no digit of it is lost. */
    public const string DATETIME = '/\A([0-9]{4}-[0-9]{2}-[0-9]{2}[Tt ][0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]+))?([Zz]|[+-][0-9]{2}:[0-9]{2})\z/';

    /**
     * Two decimal numbers compared by value without rounding, whatever their digits: `1.50` equals
     * `1.5`, `-0` equals `0`, and `10` is greater than `9.999999999999999999`.
     */
    public static function compareDecimals(string $a, string $b): ?int
    {
        if (preg_match(self::DECIMAL, $a) !== 1 || preg_match(self::DECIMAL, $b) !== 1) {
            return null;
        }

        $signA = self::sign($a);
        $signB = self::sign($b);

        if ($signA !== $signB || $signA === 0) {
            return $signA <=> $signB;
        }

        return $signA * self::compareMagnitudes(ltrim($a, '-'), ltrim($b, '-'));
    }

    /**
     * Two dates compared by day.
     */
    public static function compareDates(string $a, string $b): ?int
    {
        if (preg_match(self::DATE, $a) !== 1 || preg_match(self::DATE, $b) !== 1) {
            return null;
        }

        return self::order(strcmp($a, $b));
    }

    /**
     * Two times compared as instants, whatever their offsets, to every digit of the fraction.
     */
    public static function compareDatetimes(string $a, string $b): ?int
    {
        if (preg_match(self::DATETIME, $a, $partsA) !== 1 || preg_match(self::DATETIME, $b, $partsB) !== 1) {
            return null;
        }

        try {
            $secondsA = new DateTimeImmutable(strtoupper($partsA[1].$partsA[3]))->getTimestamp();
            $secondsB = new DateTimeImmutable(strtoupper($partsB[1].$partsB[3]))->getTimestamp();
        } catch (DateMalformedStringException) {
            return null;
        }

        if ($secondsA !== $secondsB) {
            return $secondsA <=> $secondsB;
        }

        $length = max(strlen($partsA[2]), strlen($partsB[2]));

        return self::order(strcmp(str_pad($partsA[2], $length, '0'), str_pad($partsB[2], $length, '0')));
    }

    /**
     * -1, 0 or 1 for a decimal number that matches DECIMAL.
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

<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Validation;

use Cbox\Cms\Contracts\Validation\DecimalNumber;
use Cbox\Cms\Contracts\Validation\InvalidRules;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\RuleValues;

/*
 * How the rules read whole numbers, decimal numbers, dates and date-times written as text: only
 * what the column stores unchanged counts, so a value the validator passes is never refused or
 * rounded by Postgres.
 */

it('reads a whole number only in canonical form and within a PHP int', function (string $value, ?int $expected): void {
    expect(RuleValues::integer($value))->toBe($expected);
})->with([
    ['0', 0],
    ['-12', -12],
    ['9223372036854775807', PHP_INT_MAX],
    ['9223372036854775808', null],
    ['-0', null],
    ['012', null],
    ['1.0', null],
    ['', null],
    [' 1', null],
]);

it('reads a date as a real day from year 0001', function (string $value, bool $expected): void {
    expect(RuleValues::date($value))->toBe($expected);
})->with([
    ['2024-02-29', true],
    ['0001-01-01', true],
    ['2023-02-29', false],
    ['0000-01-01', false],
    ['2026-9-29', false],
    ['2026-09-29T00:00:00Z', false],
]);

it('reads a date-time of RFC 3339 as an instant in UTC', function (string $value, ?string $utc): void {
    expect(RuleValues::datetime($value)?->format('Y-m-d\TH:i:s.uP'))->toBe($utc);
})->with([
    'UTC' => ['2026-09-29T12:00:00Z', '2026-09-29T12:00:00.000000+00:00'],
    'lower case t and z' => ['2026-09-29t12:00:00z', '2026-09-29T12:00:00.000000+00:00'],
    'an offset' => ['2026-09-29T12:00:00+02:00', '2026-09-29T10:00:00.000000+00:00'],
    'the largest offset' => ['2026-09-29T12:00:00-15:59', '2026-09-30T03:59:00.000000+00:00'],
    'microseconds' => ['2026-09-29T12:00:00.123456Z', '2026-09-29T12:00:00.123456+00:00'],
    'no offset' => ['2026-09-29T12:00:00', null],
    'a date' => ['2026-09-29', null],
    'seven digits after the point' => ['2026-09-29T12:00:00.1234567Z', null],
    'an offset Postgres refuses' => ['2026-09-29T12:00:00+16:00', null],
    'offset minutes of 60' => ['2026-09-29T12:00:00+01:60', null],
    'hour 24' => ['2026-09-29T24:00:00Z', null],
    'minute 60' => ['2026-09-29T12:60:00Z', null],
    'a leap second' => ['2026-09-29T23:59:60Z', null],
    'no real day' => ['2026-02-30T12:00:00Z', null],
    'a relative time' => ['now', null],
]);

it('reads a decimal number without leading or trailing zeros and never as negative zero', function (string $value, ?bool $negative, ?string $whole, ?string $fraction): void {
    $number = DecimalNumber::parse($value);

    expect($number?->negative)->toBe($negative)
        ->and($number?->whole)->toBe($whole)
        ->and($number?->fraction)->toBe($fraction);
})->with([
    ['12.50', false, '12', '5'],
    ['-007.500', true, '7', '5'],
    ['-0.00', false, '0', ''],
    ['0', false, '0', ''],
    ['.5', null, null, null],
    ['5.', null, null, null],
    ['1e3', null, null, null],
    ['+1', null, null, null],
]);

it('says whether a numeric column holds the number without rounding', function (string $value, int $precision, int $scale, bool $fits): void {
    expect(DecimalNumber::parse($value)?->fits($precision, $scale))->toBe($fits);
})->with([
    ['12345678.99', 10, 2, true],
    ['123456789.99', 10, 2, false],
    ['1.999', 10, 2, false],
    ['1.990', 10, 2, true],
    ['0.5', 1, 1, true],
    ['1.5', 1, 1, false],
    ['-99', 2, 0, true],
]);

it('compares decimal numbers by value', function (string $a, string $b, int $expected): void {
    $left = DecimalNumber::parse($a);
    $right = DecimalNumber::parse($b);

    expect($left)->toBeInstanceOf(DecimalNumber::class)
        ->and($right)->toBeInstanceOf(DecimalNumber::class);

    if ($left instanceof DecimalNumber && $right instanceof DecimalNumber) {
        expect($left->compare($right))->toBe($expected);
    }
})->with([
    ['1.5', '1.50', 0],
    ['-0', '0.00', 0],
    ['2', '10', -1],
    ['10', '9.99', 1],
    ['-2', '-10', 1],
    ['-1', '0', -1],
    ['0', '-0.01', 1],
    ['1.05', '1.5', -1],
    ['-1.05', '-1.5', 1],
]);

it('shows a refused argument in full up to 64 bytes, cut after that, and with control characters escaped', function (): void {
    $full = str_repeat('a', 64);

    expect(InvalidRules::argument(RuleName::MaxLength, 'an integer', $full)->getMessage())->toBe("The rule max_length takes an integer, got \"{$full}\".")
        ->and(InvalidRules::argument(RuleName::MaxLength, 'an integer', $full.'b')->getMessage())->toBe("The rule max_length takes an integer, got \"{$full}...\".")
        ->and(InvalidRules::argument(RuleName::MaxLength, 'an integer', "1\n")->getMessage())->toBe('The rule max_length takes an integer, got "1\n".');
});

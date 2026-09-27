<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\DateStrings;

/*
 * Which date strings and createFromFormat() formats make PHP read the system clock, for
 * SystemClockRule (GUARDRAILS 2.3).
 */

it('finds the date strings whose instant depends on the current time', function (bool $dependsOnNow, string $value): void {
    expect(DateStrings::dependsOnNow($value))->toBe($dependsOnNow);
})->with([
    'empty' => [true, ''],
    'blank' => [true, '  '],
    'now' => [true, 'now'],
    'NOW' => [true, 'NOW'],
    'today' => [true, 'today'],
    'midnight' => [true, 'midnight'],
    'tomorrow' => [true, 'tomorrow'],
    'a relative interval' => [true, '+1 day'],
    'a weekday' => [true, 'monday'],
    'a time of day' => [true, '10:00'],
    'a day without a year' => [true, 'January 1'],
    'a relative day of a month' => [true, 'first monday of january'],
    'a date' => [false, '2026-01-01'],
    'a date and time' => [false, '2026-01-01 10:00:00'],
    'an offset' => [false, '2026-01-01T10:00:00+02:00'],
    'microseconds' => [false, '2026-01-01T10:00:00.123456Z'],
    'a unix timestamp' => [false, '@1767225600'],
    'a date moved by an interval' => [false, '2026-01-01 +1 day'],
    'no date at all' => [false, 'not a date'],
]);

it('finds the formats that take a field from the current time', function (bool $dependsOnNow, string $format): void {
    expect(DateStrings::formatDependsOnNow($format))->toBe($dependsOnNow);
})->with([
    'a date' => [true, 'Y-m-d'],
    'a time' => [true, 'H:i:s'],
    'no seconds' => [true, 'Y-m-d H:i'],
    'an escaped field' => [true, 'Y-m-d H:i:\s'],
    'a day of the year' => [true, 'Y z'],
    'a reset' => [false, '!Y-m-d'],
    'a reset of the rest' => [false, 'Y-m-d|'],
    'every field' => [false, 'Y-m-d H:i:s'],
    'every field with a literal' => [false, 'Y-m-d\TH:i:s'],
    'every field in other forms' => [false, 'y n j G i s'],
    'a day of the year and a time' => [false, 'Y z H:i:s'],
    'a unix timestamp' => [false, 'U'],
    'a unix timestamp with microseconds' => [false, 'U.u'],
]);

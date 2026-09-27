<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\Bounds;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Closure;
use LogicException;
use ReflectionMethod;
use ReflectionParameter;

/*
 * The bounds of the date, datetime and decimal field types as value objects that check their own
 * form, the comparisons behind the min and max rule of the blueprint reader, and the column names
 * behind the length rule.
 */

/**
 * @param  Closure(): object  $make
 */
function boundRefusal(Closure $make): GenerationFailed
{
    try {
        $make();
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    throw new LogicException('The bound was accepted.');
}

it('refuses a date that is not a day of the calendar as YYYY-MM-DD', function (string $value): void {
    $failed = boundRefusal(static fn (): BlueprintDate => new BlueprintDate($value));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toStartWith(sprintf('"%s" is not a date.', $value));
})->with([
    'month 13 and day 45' => ['2026-13-45'],
    'the 30th of February' => ['2026-02-30'],
    'the 29th of February outside a leap year' => ['2025-02-29'],
    'year 0000' => ['0000-01-01'],
    'digits left out' => ['2026-1-1'],
    'a time' => ['2026-01-01T00:00:00Z'],
    'a trailing newline' => ["2026-01-01\n"],
    'empty' => [''],
]);

it('keeps a date as written', function (string $value): void {
    expect(new BlueprintDate($value)->value)->toBe($value);
})->with(['2026-01-01', '2024-02-29', '0001-01-01', '9999-12-31']);

it('refuses a time that is not RFC 3339 with its offset', function (string $value): void {
    $failed = boundRefusal(static fn (): BlueprintDatetime => new BlueprintDatetime($value));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toStartWith(sprintf('"%s" is not a time of RFC 3339 with an offset.', $value));
})->with([
    'no offset' => ['2026-01-01T00:00:00'],
    'a space for T' => ['2026-01-01 00:00:00Z'],
    'a date only' => ['2026-01-01'],
    'month 13' => ['2026-13-01T00:00:00Z'],
    'the 30th of February' => ['2026-02-30T00:00:00Z'],
    'hour 24' => ['2026-01-01T24:00:00Z'],
    'minute 60' => ['2026-01-01T00:60:00Z'],
    'second 61' => ['2026-01-01T00:00:61Z'],
    'an offset of 24 hours' => ['2026-01-01T00:00:00+24:00'],
    'an offset of 60 minutes' => ['2026-01-01T00:00:00+01:60'],
    'an offset without a colon' => ['2026-01-01T00:00:00+0100'],
    'a point without digits' => ['2026-01-01T00:00:00.Z'],
    'a trailing newline' => ["2026-01-01T00:00:00Z\n"],
]);

it('keeps a time as written and as the instant it names', function (string $value, int $seconds, string $fraction): void {
    $datetime = new BlueprintDatetime($value);

    expect($datetime->value)->toBe($value)
        ->and($datetime->seconds)->toBe($seconds)
        ->and($datetime->fraction)->toBe($fraction);
})->with([
    'UTC' => ['2026-01-01T00:00:00Z', 1767225600, ''],
    'a positive offset' => ['2026-01-01T01:30:00+01:30', 1767225600, ''],
    'a negative offset' => ['2025-12-31T19:00:00-05:00', 1767225600, ''],
    'a lowercase t and z' => ['2026-01-01t00:00:00z', 1767225600, ''],
    'a fraction with trailing zeros' => ['2026-01-01T00:00:00.500Z', 1767225600, '5'],
    'a fraction beyond microseconds' => ['2026-01-01T00:00:00.0000001Z', 1767225600, '0000001'],
    'a leap second' => ['2016-12-31T23:59:60Z', 1483228800, ''],
    'before the epoch' => ['1969-12-31T23:59:59Z', -1, ''],
]);

it('refuses a decimal bound that is not digits with an optional minus and decimal point', function (string $value): void {
    $failed = boundRefusal(static fn (): DecimalBound => new DecimalBound($value));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toStartWith(sprintf('"%s" is not a decimal number.', $value));
})->with([
    'an exponent' => ['1e5'],
    'no integer part' => ['.5'],
    'no fraction after the point' => ['5.'],
    'a plus sign' => ['+5'],
    'a comma' => ['1,5'],
    'a trailing newline' => ["1.5\n"],
    'empty' => [''],
]);

it('keeps a decimal bound as its digits', function (string $value): void {
    expect(new DecimalBound($value)->value)->toBe($value);
})->with(['0', '-12.50', '007', '12345678901234567890.123456789012345678']);

it('takes the bounds of the option DTOs only as value objects', function (string $class, string $type): void {
    $parameters = new ReflectionMethod($class, '__construct')->getParameters();
    $bounds = array_values(array_filter($parameters, static fn (ReflectionParameter $parameter): bool => in_array($parameter->getName(), ['min', 'max'], true)));

    expect($bounds)->toHaveCount(2);

    foreach ($bounds as $bound) {
        expect((string) $bound->getType())->toBe('?'.$type);
    }
})->with([
    'date' => [DateOptions::class, BlueprintDate::class],
    'datetime' => [DatetimeOptions::class, BlueprintDatetime::class],
    'decimal' => [DecimalOptions::class, DecimalBound::class],
]);

it('reports a min above the max with the bounds as written', function (): void {
    $field = new SourceLocation('schema/event.yaml', '/fields/0');

    $date = new DateOptions(new BlueprintDate('2026-01-02'), new BlueprintDate('2026-01-01'))->problems($field);
    $datetime = new DatetimeOptions(new BlueprintDatetime('2026-01-01T01:00:00+00:30'), new BlueprintDatetime('2026-01-01T00:00:00Z'))->problems($field);
    $decimal = new DecimalOptions(4, 2, new DecimalBound('10.5'), new DecimalBound('9.75'), null)->problems($field);

    expect($date[0]->code)->toBe(GenerateErrorCode::MinAboveMax)
        ->and($date[0]->message)->toContain('min 2026-01-02 is greater than max 2026-01-01')
        ->and($datetime[0]->message)->toContain('min 2026-01-01T01:00:00+00:30 is greater than max 2026-01-01T00:00:00Z')
        ->and($decimal[0]->message)->toContain('min 10.5 is greater than max 9.75');
});

it('compares decimal numbers by value, digit by digit', function (string $a, string $b, int $order): void {
    expect(Bounds::compareDecimals(new DecimalBound($a), new DecimalBound($b)))->toBe($order)
        ->and(Bounds::compareDecimals(new DecimalBound($b), new DecimalBound($a)))->toBe(-$order);
})->with([
    'equal' => ['1.5', '1.5', 0],
    'trailing zeros' => ['1.50', '1.5', 0],
    'leading zeros' => ['007', '7.0', 0],
    'zero and negative zero' => ['-0.00', '0', 0],
    'longer integer part' => ['10', '9.999999999999999999', 1],
    'fraction' => ['0.12', '0.119', 1],
    'negative against positive' => ['-1', '0.5', -1],
    'two negatives' => ['-1.5', '-1.75', 1],
    'beyond a float' => ['12345678901234567890.2', '12345678901234567890.1', 1],
    'beyond an integer' => ['99999999999999999999', '100000000000000000000', -1],
]);

it('compares dates by day', function (): void {
    $date = static fn (string $value): BlueprintDate => new BlueprintDate($value);

    expect(Bounds::compareDates($date('2026-01-02'), $date('2026-01-01')))->toBe(1)
        ->and(Bounds::compareDates($date('2026-01-01'), $date('2026-01-01')))->toBe(0)
        ->and(Bounds::compareDates($date('2025-12-31'), $date('2026-01-01')))->toBe(-1);
});

it('compares times as instants, across offsets and to every digit of the fraction', function (string $a, string $b, int $order): void {
    expect(Bounds::compareDatetimes(new BlueprintDatetime($a), new BlueprintDatetime($b)))->toBe($order)
        ->and(Bounds::compareDatetimes(new BlueprintDatetime($b), new BlueprintDatetime($a)))->toBe(-$order);
})->with([
    'equal across offsets' => ['2026-01-01T01:00:00+01:00', '2026-01-01T00:00:00Z', 0],
    'earlier by its offset' => ['2026-01-01T01:00:00+02:00', '2025-12-31T23:30:00Z', -1],
    'a lowercase t and z' => ['2026-01-01t00:00:01z', '2026-01-01T00:00:00Z', 1],
    'a fraction with trailing zeros' => ['2026-01-01T00:00:00.500Z', '2026-01-01T00:00:00.5Z', 0],
    'a digit beyond microseconds' => ['2026-01-01T00:00:00.0000002Z', '2026-01-01T00:00:00.0000001Z', 1],
    'a fraction against none' => ['2026-01-01T00:00:00.1Z', '2026-01-01T00:00:00Z', 1],
    'a leap second and the next minute' => ['2016-12-31T23:59:60Z', '2017-01-01T00:00:00Z', 0],
    'on the day before by its offset' => ['2026-01-01T00:30:00+01:00', '2025-12-31T23:59:59Z', -1],
]);

it('names the column of a type field by its handle and of an extension field by ext__<namespace>__<handle>', function (): void {
    $own = ColumnName::ofTypeField(new Handle('tax_code'));
    $extension = ColumnName::ofExtensionField(Owner::app(), new Handle('tax_code'));
    $long = ColumnName::ofExtensionField(new Owner('acme'), new Handle(str_repeat('a', 53)));

    expect($own->value)->toBe('tax_code')
        ->and($extension->value)->toBe('ext__app__tax_code')
        ->and($extension->fits())->toBeTrue()
        ->and($long->bytes())->toBe(64)
        ->and($long->fits())->toBeFalse();
});

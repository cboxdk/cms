<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Schema\Domain\Bounds;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/*
 * The comparisons behind the min and max rule of the blueprint reader, and the column names behind
 * the length rule.
 */

it('compares decimal numbers by value, digit by digit', function (string $a, string $b, int $order): void {
    expect(Bounds::compareDecimals($a, $b))->toBe($order)
        ->and(Bounds::compareDecimals($b, $a))->toBe(-$order);
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
    expect(Bounds::compareDates('2026-01-02', '2026-01-01'))->toBe(1)
        ->and(Bounds::compareDates('2026-01-01', '2026-01-01'))->toBe(0)
        ->and(Bounds::compareDates('2025-12-31', '2026-01-01'))->toBe(-1);
});

it('compares times as instants, across offsets and to every digit of the fraction', function (string $a, string $b, int $order): void {
    expect(Bounds::compareDatetimes($a, $b))->toBe($order)
        ->and(Bounds::compareDatetimes($b, $a))->toBe(-$order);
})->with([
    'equal across offsets' => ['2026-01-01T01:00:00+01:00', '2026-01-01T00:00:00Z', 0],
    'earlier by its offset' => ['2026-01-01T01:00:00+02:00', '2025-12-31T23:30:00Z', -1],
    'a lowercase t and z' => ['2026-01-01t00:00:01z', '2026-01-01T00:00:00Z', 1],
    'a fraction with trailing zeros' => ['2026-01-01T00:00:00.500Z', '2026-01-01T00:00:00.5Z', 0],
    'a digit beyond microseconds' => ['2026-01-01T00:00:00.0000002Z', '2026-01-01T00:00:00.0000001Z', 1],
    'a fraction against none' => ['2026-01-01T00:00:00.1Z', '2026-01-01T00:00:00Z', 1],
]);

it('does not compare values that are not of their form, which the blueprint schema reports', function (): void {
    expect(Bounds::compareDecimals('1e3', '1'))->toBeNull()
        ->and(Bounds::compareDecimals('1', '.5'))->toBeNull()
        ->and(Bounds::compareDates('2026-1-1', '2026-01-01'))->toBeNull()
        ->and(Bounds::compareDatetimes('2026-01-01', '2026-01-01T00:00:00Z'))->toBeNull()
        ->and(Bounds::compareDatetimes('2026-01-01T00:00:00Z', '2026-01-01T00:00:00'))->toBeNull();
});

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

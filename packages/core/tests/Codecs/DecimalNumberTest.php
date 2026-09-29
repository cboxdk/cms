<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Core\Codecs\Domain\DecimalNumber;
use LogicException;

/*
 * Decimal numbers as text (GUARDRAILS 2.2): parsed without a float, written with exactly the scale
 * of their column, and compared by value.
 */

it('writes a decimal with exactly the scale of its column', function (string $value, int $precision, int $scale, ?string $fixed): void {
    expect(DecimalNumber::parse($value)?->fixed($precision, $scale))->toBe($fixed);
})->with([
    'padded' => ['12.5', 10, 2, '12.50'],
    'leading zeros dropped' => ['0012.50', 10, 2, '12.50'],
    'trailing zeros past the scale' => ['12.5000', 10, 2, '12.50'],
    'negative' => ['-3', 5, 1, '-3.0'],
    'negative zero' => ['-0.00', 5, 2, '0.00'],
    'zero' => ['0', 5, 2, '0.00'],
    'scale 0 without a point' => ['42', 5, 0, '42'],
    'scale 0 from a point and zeros' => ['42.000', 5, 0, '42'],
    'all digits before the point' => ['999', 5, 2, '999.00'],
    'a fraction below one' => ['.5', 5, 2, null],
    'too many digits before the point' => ['1000', 5, 2, null],
    'a digit past the scale' => ['1.005', 5, 2, null],
    'a digit past scale 0' => ['1.5', 5, 0, null],
    'precision equal to the scale' => ['0.25', 2, 2, '0.25'],
    'precision equal to the scale, a digit before the point' => ['1.25', 2, 2, null],
]);

it('parses only decimal numbers', function (string $value): void {
    expect(DecimalNumber::parse($value))->toBeNull();
})->with([
    'empty' => [''],
    'a point alone' => ['.'],
    'a trailing point' => ['1.'],
    'an exponent' => ['1e5'],
    'a plus sign' => ['+1'],
    'spaces' => [' 1'],
    'a trailing newline' => ["1\n"],
    'a comma' => ['1,5'],
    'two points' => ['1.2.3'],
    'a float word' => ['NAN'],
]);

it('compares decimals by value', function (string $a, string $b, int $order): void {
    $left = DecimalNumber::parse($a);
    $right = DecimalNumber::parse($b);

    expect($left)->not->toBeNull()
        ->and($right)->not->toBeNull()
        ->and($left?->compare($right ?? throw new LogicException))->toBe($order)
        ->and($right?->compare($left ?? throw new LogicException))->toBe(-$order);
})->with([
    'equal with other zeros' => ['1.50', '01.5', 0],
    'zero and negative zero' => ['0', '-0.000', 0],
    'by the digits before the point' => ['10', '9.99', 1],
    'by the fraction' => ['1.25', '1.3', -1],
    'by the length of the fraction' => ['1.2', '1.25', -1],
    'negative against positive' => ['-5', '1', -1],
    'negative against zero' => ['-0.01', '0', -1],
    'positive against zero' => ['0.01', '0', 1],
    'two negatives' => ['-10', '-9', -1],
    'two negatives by the fraction' => ['-1.25', '-1.3', 1],
    'two negatives by the length' => ['-100', '-99.9', -1],
    'below one, by the fraction' => ['0.5', '0.25', 1],
    'below one against one' => ['0.99', '1', -1],
    'a longer fraction that is smaller' => ['1.3', '1.25', 1],
    'negative below one against a negative whole' => ['-0.5', '-1', 1],
]);

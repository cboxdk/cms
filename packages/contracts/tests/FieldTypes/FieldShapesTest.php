<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\FieldTypes;

use Cbox\Cms\Contracts\FieldTypes\BooleanShape;
use Cbox\Cms\Contracts\FieldTypes\DateShape;
use Cbox\Cms\Contracts\FieldTypes\DatetimeShape;
use Cbox\Cms\Contracts\FieldTypes\DecimalShape;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldShape;
use Cbox\Cms\Contracts\FieldTypes\LongTextShape;
use Cbox\Cms\Contracts\FieldTypes\SelectChoice;
use Cbox\Cms\Contracts\FieldTypes\SelectShape;
use Cbox\Cms\Contracts\FieldTypes\TextFormat;
use Cbox\Cms\Contracts\FieldTypes\TextShape;

/*
 * The shapes an addon's field type gives the generators (PRD 11.12, 13.1): one per base, each held
 * to the limits of the core field type of its base in blueprint v1.
 */

it('names the base of each shape, one per case of FieldBase', function (): void {
    $shapes = [
        new TextShape,
        new LongTextShape,
        new IntegerShape,
        new DecimalShape(10, 2),
        new BooleanShape,
        new DateShape,
        new DatetimeShape,
        new SelectShape([new SelectChoice('small', 'Small')]),
    ];

    expect(array_map(static fn (FieldShape $shape): FieldBase => $shape->base(), $shapes))->toBe(FieldBase::cases())
        ->and(new TextShape()->maxLength)->toBe(255)
        ->and(new TextShape()->format)->toBe(TextFormat::Plain)
        ->and(new LongTextShape()->maxLength)->toBe(10000)
        ->and(new SelectShape([new SelectChoice('a', 'A'), new SelectChoice('b', 'B')], true, 1, 2)->choices)->toHaveCount(2);
});

it('refuses a shape outside the limits of its base', function (callable $shape, string $message): void {
    expect($shape)->toThrow(InvalidFieldShape::class, $message);
})->with([
    'a text longer than 10000' => [static fn (): TextShape => new TextShape(maxLength: 10001), 'The maximum length of a text shape is from 1 to 10000, got 10001.'],
    'a text with a negative minimum' => [static fn (): TextShape => new TextShape(minLength: -1), 'The minimum length of a text shape is at least 0, got -1.'],
    'a text whose minimum is above its maximum' => [static fn (): TextShape => new TextShape(minLength: 20, maxLength: 10), 'The minimum length of a text shape, 20, is above its maximum, 10.'],
    'a long text longer than 1000000' => [static fn (): LongTextShape => new LongTextShape(maxLength: 1000001), 'The maximum length of a long text shape is from 1 to 1000000, got 1000001.'],
    'an integer whose minimum is above its maximum' => [static fn (): IntegerShape => new IntegerShape(5, 1), 'The minimum value of an integer shape, 5, is above its maximum, 1.'],
    'an empty unit' => [static fn (): IntegerShape => new IntegerShape(unit: ''), 'The unit of an integer shape is 1 to 20 characters, got "".'],
    'a precision above 38' => [static fn (): DecimalShape => new DecimalShape(39, 2), 'The precision of a decimal shape is from 1 to 38, got 39.'],
    'a scale above the precision' => [static fn (): DecimalShape => new DecimalShape(4, 5), 'The scale of a decimal shape is from 0 to 4, got 5.'],
    'a bound that is not a decimal' => [static fn (): DecimalShape => new DecimalShape(4, 2, min: '1e3'), 'The minimum of a decimal shape is a decimal number such as "-12.50", got "1e3".'],
    'no choices' => [static fn (): SelectShape => new SelectShape([]), 'A select shape has 1 to 500 choices, got 0.'],
    'a value twice' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A'), new SelectChoice('a', 'B')]), 'A select shape has each value once, got a, a.'],
    'item counts on a single select' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], maxItems: 2), 'Only a multiple select shape has a minimum or maximum number of items.'],
    'more than 500 items' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], true, maxItems: 501), 'The maximum number of items of a select shape is from 1 to 500, got 501.'],
    'an empty label' => [static fn (): SelectChoice => new SelectChoice('a', ''), 'The label of a select choice is 1 to 100 characters, got "".'],
]);

it('takes a shape at the limits of its base', function (): void {
    $choices = array_map(static fn (int $index): SelectChoice => new SelectChoice('c'.$index, 'Choice '.$index), range(1, 500));

    expect(new TextShape(0, 10000)->maxLength)->toBe(10000)
        ->and(new TextShape(1, 1)->minLength)->toBe(1)
        ->and(new LongTextShape(0, 1000000)->maxLength)->toBe(1000000)
        ->and(new IntegerShape(5, 5, str_repeat('u', 20))->unit)->toBe(str_repeat('u', 20))
        ->and(new DecimalShape(38, 38)->scale)->toBe(38)
        ->and(new DecimalShape(1, 0, '-1', '1.5')->min)->toBe('-1')
        ->and(new SelectShape($choices, true, 0, 500)->choices)->toHaveCount(500)
        ->and(new SelectShape([new SelectChoice('a', 'A')], true, 1, 1)->maxItems)->toBe(1)
        ->and(new SelectChoice('a', str_repeat('l', 100))->label)->toBe(str_repeat('l', 100));
});

it('refuses a shape just past the limits of its base', function (callable $shape, string $message): void {
    expect($shape)->toThrow(InvalidFieldShape::class, $message);
})->with([
    'a text of no characters' => [static fn (): TextShape => new TextShape(maxLength: 0), 'The maximum length of a text shape is from 1 to 10000, got 0.'],
    'a long text of no characters' => [static fn (): LongTextShape => new LongTextShape(maxLength: 0), 'The maximum length of a long text shape is from 1 to 1000000, got 0.'],
    'a long text whose minimum is above its maximum' => [static fn (): LongTextShape => new LongTextShape(11, 10), 'The minimum length of a long text shape, 11, is above its maximum, 10.'],
    'a unit of 21 characters' => [static fn (): IntegerShape => new IntegerShape(unit: str_repeat('u', 21)), 'The unit of an integer shape is 1 to 20 characters'],
    'a precision of 0' => [static fn (): DecimalShape => new DecimalShape(0, 0), 'The precision of a decimal shape is from 1 to 38, got 0.'],
    'a negative scale' => [static fn (): DecimalShape => new DecimalShape(4, -1), 'The scale of a decimal shape is from 0 to 4, got -1.'],
    'a maximum that is not a decimal' => [static fn (): DecimalShape => new DecimalShape(4, 2, max: '1.'), 'The maximum of a decimal shape is a decimal number such as "-12.50", got "1.".'],
    'a decimal unit of 21 characters' => [static fn (): DecimalShape => new DecimalShape(4, 2, unit: str_repeat('u', 21)), 'The unit of a decimal shape is 1 to 20 characters'],
    '501 choices' => [static fn (): SelectShape => new SelectShape(array_map(static fn (int $index): SelectChoice => new SelectChoice('c'.$index, 'C'), range(1, 501))), 'A select shape has 1 to 500 choices, got 501.'],
    'a minimum on a single select' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], minItems: 0), 'Only a multiple select shape has a minimum or maximum number of items.'],
    'a negative minimum of items' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], true, -1), 'The minimum number of items of a select shape is at least 0, got -1.'],
    'no items at most' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], true, maxItems: 0), 'The maximum number of items of a select shape is from 1 to 500, got 0.'],
    'more items at least than at most' => [static fn (): SelectShape => new SelectShape([new SelectChoice('a', 'A')], true, 3, 2), 'The minimum number of items of a select shape, 3, is above its maximum, 2.'],
    'a label of 101 characters' => [static fn (): SelectChoice => new SelectChoice('a', str_repeat('l', 101)), 'The label of a select choice is 1 to 100 characters'],
]);

<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fields;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldReader;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldWriter;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use DateTimeImmutable;

/*
 * FieldReader and FieldWriter, through which the generated records convert to and from the
 * kernel's generic field values (PRD 11.12): each field type is read as its PHP value and written
 * back as the value the kernel holds, a field without a value is null, and a value of the wrong
 * kind is refused with the field's path.
 */

function readerOf(string $handle, FieldValue $value): FieldReader
{
    return FieldReader::of(new FieldMap(new NamedValue(new FieldHandle($handle), $value)));
}

it('reads each kind of value and writes it back', function (FieldValue $value, string $read, string $write, mixed $php): void {
    $reader = readerOf('field', $value);
    $argument = $read === 'choice' || $read === 'choices' ? [Suit::class] : [];
    $result = $reader->{$read}('field', ...$argument);

    expect($result)->toEqual($php)
        ->and(FieldWriter::{$write}($result))->toEqual($value)
        ->and($reader->{$read.'OrNull'}('field', ...$argument))->toEqual($php);
})->with([
    'text' => [new TextValue('a'), 'text', 'text', 'a'],
    'integer' => [new IntegerValue(-3), 'integer', 'integer', -3],
    'decimal' => [new DecimalValue('1.50'), 'decimal', 'decimal', '1.5'],
    'boolean' => [new BooleanValue(true), 'boolean', 'boolean', true],
    'date' => [new DateValue('2026-02-28'), 'date', 'date', new DateTimeImmutable('2026-02-28T00:00:00+00:00')],
    'datetime' => [new DateTimeValue(new DateTimeImmutable('2026-02-28T13:14:15.5+00:00')), 'dateTime', 'dateTime', new DateTimeImmutable('2026-02-28T13:14:15.5+00:00')],
    'list' => [new ListValue(new TextValue('x')), 'list', 'list', new ListValue(new TextValue('x'))],
    'choice' => [new TextValue('spades'), 'choice', 'choice', Suit::Spades],
    'choices' => [new ListValue(new TextValue('spades'), new TextValue('hearts')), 'choices', 'choices', [Suit::Spades, Suit::Hearts]],
]);

it('reads an absent field and one that holds NullValue as null, and writes null as NullValue', function (string $method, string $write): void {
    $argument = str_starts_with($method, 'choice') ? [Suit::class] : [];

    expect(FieldReader::of(null)->{$method}('field', ...$argument))->toBeNull()
        ->and(readerOf('field', new NullValue)->{$method}('field', ...$argument))->toBeNull()
        ->and(FieldWriter::{$write}(null))->toEqual(new NullValue);
})->with([
    ['textOrNull', 'text'],
    ['integerOrNull', 'integer'],
    ['decimalOrNull', 'decimal'],
    ['booleanOrNull', 'boolean'],
    ['dateOrNull', 'date'],
    ['dateTimeOrNull', 'dateTime'],
    ['listOrNull', 'list'],
    ['choiceOrNull', 'choice'],
    ['choicesOrNull', 'choices'],
    ['groupOrNull', 'group'],
    ['groupsOrNull', 'groups'],
]);

it('refuses a required field without a value', function (string $method): void {
    $argument = str_starts_with($method, 'choice') ? [Suit::class] : [];

    expect(fn (): mixed => readerOf('field', new NullValue)->{$method}('field', ...$argument))
        ->toThrow(InvalidFieldValue::class, 'The field "field" is required and holds no value.');
})->with(['text', 'integer', 'decimal', 'boolean', 'date', 'dateTime', 'list', 'choice', 'choices', 'group', 'groups']);

it('refuses a value of another kind than the field type holds', function (string $method, string $expected): void {
    $argument = str_starts_with($method, 'choice') ? [Suit::class] : [];

    expect(fn (): mixed => readerOf('field', new ImpostorValue(1))->{$method}('field', ...$argument))
        ->toThrow(InvalidFieldValue::class, 'The field "field" holds ImpostorValue, expected '.$expected.'.');
})->with([
    ['textOrNull', 'TextValue'],
    ['integerOrNull', 'IntegerValue'],
    ['decimalOrNull', 'DecimalValue'],
    ['booleanOrNull', 'BooleanValue'],
    ['dateOrNull', 'DateValue'],
    ['dateTimeOrNull', 'DateTimeValue'],
    ['listOrNull', 'ListValue'],
    ['choiceOrNull', 'TextValue'],
    ['choicesOrNull', 'ListValue'],
    ['groupOrNull', 'GroupValue'],
    ['groupsOrNull', 'ListValue'],
]);

it('names the item of a list that holds another kind', function (): void {
    expect(fn (): mixed => readerOf('suits', new ListValue(new TextValue('hearts'), new IntegerValue(2)))->choices('suits', Suit::class))
        ->toThrow(InvalidFieldValue::class, 'The field "suits.1" holds IntegerValue, expected TextValue.')
        ->and(fn (): mixed => readerOf('rows', new ListValue(new TextValue('a')))->groups('rows'))
        ->toThrow(InvalidFieldValue::class, 'The field "rows.0" holds TextValue, expected GroupValue.');
});

it('refuses text that is none of the options', function (): void {
    expect(fn (): mixed => readerOf('suit', new TextValue('clubs'))->choice('suit', Suit::class))
        ->toThrow(InvalidFieldValue::class, 'The field "suit" holds "clubs", which is not one of its options.')
        ->and(fn (): mixed => readerOf('suits', new ListValue(new TextValue('clubs')))->choices('suits', Suit::class))
        ->toThrow(InvalidFieldValue::class, 'The field "suits" holds "clubs", which is not one of its options.');
});

it('reads a group\'s fields with paths below the group, and a repeated group\'s by index', function (): void {
    $group = new GroupValue(new FieldMap(new NamedValue(new FieldHandle('name'), new TextValue('Ada')), new NamedValue(new FieldHandle('age'), new NullValue)));
    $reader = readerOf('owner', $group);

    expect($reader->group('owner')->text('name'))->toBe('Ada')
        ->and(fn (): mixed => $reader->group('owner')->integer('age'))->toThrow(InvalidFieldValue::class, 'The field "owner.age" is required')
        ->and(fn (): mixed => readerOf('rows', new ListValue($group, $group))->groups('rows')[1]->integer('age'))
        ->toThrow(InvalidFieldValue::class, 'The field "rows.1.age" is required')
        ->and(FieldWriter::group($group->fields))->toEqual($group)
        ->and(FieldWriter::groups([$group->fields]))->toEqual(new ListValue($group));
});

it('writes a date as the calendar day in UTC and an instant in UTC', function (): void {
    $late = new DateTimeImmutable('2026-03-01T01:30:00+02:00');

    expect(FieldWriter::date($late))->toEqual(new DateValue('2026-02-28'))
        ->and(FieldWriter::dateTime($late))->toEqual(new DateTimeValue(new DateTimeImmutable('2026-02-28T23:30:00Z')))
        ->and(readerOf('day', new DateValue('2026-02-28'))->date('day')->getTimezone()->getName())->toBe('UTC');
});

it('refuses a decimal that is not one', function (): void {
    expect(fn (): FieldValue => FieldWriter::decimal('1e5'))->toThrow(InvalidFieldValue::class);
});

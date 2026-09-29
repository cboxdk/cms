<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fields;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use DateTimeImmutable;

/*
 * The kernel's generic field-value structure (GUARDRAILS 2.4, PRD 11.12): every value compares by
 * value, maps are sorted and unique by key, and an extender's fields live under its namespace
 * next to the owner's.
 */

function field(string $handle, FieldValue $value): NamedValue
{
    return new NamedValue(new FieldHandle($handle), $value);
}

it('accepts handles as the blueprint does, and refuses the rest', function (): void {
    expect(new FieldHandle('title')->value)->toBe('title')
        ->and(new FieldHandle('tax_code_2')->equals(new FieldHandle('tax_code_2')))->toBeTrue()
        ->and(new FieldHandle('a')->equals(new FieldHandle('b')))->toBeFalse()
        ->and(new FieldHandle(str_repeat('a', 63))->value)->toHaveLength(63);

    foreach (['', 'Title', '1title', 'tax__code', 'tax_', '_tax', 'ext', 'cms_status', str_repeat('a', 64), 'ext.app'] as $handle) {
        expect(static fn (): FieldHandle => new FieldHandle($handle))
            ->toThrow(InvalidFieldValue::class, sprintf('A field handle is lowercase snake_case of at most 63 bytes without a double underscore, and neither "ext" nor starting with "cms_", got "%s".', $handle));
    }

    expect(new FieldHandle('external')->value)->toBe('external')
        ->and(new FieldHandle('cms')->value)->toBe('cms');
});

it('accepts extension namespaces as the blueprint does, and refuses the rest', function (): void {
    expect(new FieldNamespace('app')->value)->toBe('app')
        ->and(new FieldNamespace('acme2')->equals(new FieldNamespace('acme2')))->toBeTrue()
        ->and(new FieldNamespace('acme')->equals(new FieldNamespace('app')))->toBeFalse()
        ->and(new FieldNamespace(str_repeat('a', 20))->value)->toHaveLength(20);

    foreach (['', 'ext', 'Acme', '2acme', 'acme_shop', str_repeat('a', 21)] as $namespace) {
        expect(static fn (): FieldNamespace => new FieldNamespace($namespace))
            ->toThrow(InvalidFieldValue::class, sprintf('A field namespace is a lowercase letter followed by at most 19 lowercase letters and digits, and not "ext", got "%s".', $namespace));
    }
});

it('compares every scalar value by kind and value', function (): void {
    expect(new NullValue()->equals(new NullValue))->toBeTrue()
        ->and(new NullValue()->equals(new TextValue('')))->toBeFalse()
        ->and(new TextValue('æ')->equals(new TextValue('æ')))->toBeTrue()
        ->and(new TextValue('a')->equals(new TextValue('b')))->toBeFalse()
        ->and(new TextValue('1')->equals(new IntegerValue(1)))->toBeFalse()
        ->and(new IntegerValue(1)->equals(new IntegerValue(1)))->toBeTrue()
        ->and(new IntegerValue(1)->equals(new IntegerValue(2)))->toBeFalse()
        ->and(new IntegerValue(1)->equals(new DecimalValue('1')))->toBeFalse()
        ->and(new BooleanValue(true)->equals(new BooleanValue(true)))->toBeTrue()
        ->and(new BooleanValue(true)->equals(new BooleanValue(false)))->toBeFalse()
        ->and(new BooleanValue(false)->equals(new IntegerValue(0)))->toBeFalse()
        ->and(new DateValue('2026-09-29')->equals(new DateValue('2026-09-29')))->toBeTrue()
        ->and(new DateValue('2026-09-29')->equals(new DateValue('2026-09-30')))->toBeFalse()
        ->and(new DateValue('2026-09-29')->equals(new TextValue('2026-09-29')))->toBeFalse()
        ->and(new DecimalValue('1.5')->equals(new DecimalValue('1.50')))->toBeTrue()
        ->and(new DecimalValue('1.5')->equals(new DecimalValue('1.6')))->toBeFalse()
        ->and(new DecimalValue('1.5')->equals(new TextValue('1.5')))->toBeFalse()
        ->and(static fn (): TextValue => new TextValue("\xff"))->toThrow(InvalidFieldValue::class, 'A text value must be valid UTF-8.');
});

it('tells a value from another kind that holds the same PHP value', function (): void {
    expect(new TextValue('a')->equals(new ImpostorValue('a')))->toBeFalse()
        ->and(new IntegerValue(1)->equals(new ImpostorValue(1)))->toBeFalse()
        ->and(new BooleanValue(true)->equals(new ImpostorValue(true)))->toBeFalse()
        ->and(new DateValue('2026-09-29')->equals(new ImpostorValue('2026-09-29')))->toBeFalse()
        ->and(new DecimalValue('1.5')->equals(new ImpostorValue('1.5')))->toBeFalse();
});

it('keeps values given by name as a list', function (): void {
    $list = new ListValue(...['first' => new TextValue('a'), 'second' => new TextValue('b')]);

    expect(array_keys($list->items))->toBe([0, 1]);
});

it('holds a decimal in its canonical form', function (string $given, string $canonical): void {
    expect(new DecimalValue($given)->value)->toBe($canonical);
})->with([
    ['0', '0'],
    ['-0', '0'],
    ['-0.000', '0'],
    ['007', '7'],
    ['012.50', '12.5'],
    ['-12.50', '-12.5'],
    ['1.000', '1'],
    ['0.05', '0.05'],
    ['-0.05', '-0.05'],
    ['12345678901234567890.123456789', '12345678901234567890.123456789'],
]);

it('refuses a decimal that is not digits with an optional sign and fraction', function (string $given): void {
    expect(static fn (): DecimalValue => new DecimalValue($given))
        ->toThrow(InvalidFieldValue::class, sprintf('A decimal value is an optional minus sign, digits, and optionally a point and digits, such as "-12.50", got "%s".', $given));
})->with(['', '+1', '1.', '.5', '1e3', '1,5', ' 1', '--1']);

it('holds real dates only', function (): void {
    expect(new DateValue('2024-02-29')->value)->toBe('2024-02-29')
        ->and(new DateValue('0001-01-01')->value)->toBe('0001-01-01');

    foreach (['2026-02-29', '0000-01-01', '2026-13-01', '2026-9-29', '29-09-2026', '2026-09-29T00:00:00Z'] as $date) {
        expect(static fn (): DateValue => new DateValue($date))
            ->toThrow(InvalidFieldValue::class, sprintf('A date value is a real date as YYYY-MM-DD from year 0001, such as "2026-09-29", got "%s".', $date));
    }
});

it('holds an instant in UTC and compares it to the microsecond', function (): void {
    $value = new DateTimeValue(new DateTimeImmutable('2026-09-29T14:00:00.123456+02:00'));

    expect($value->value->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-29T12:00:00.123456+00:00')
        ->and($value->equals(new DateTimeValue(new DateTimeImmutable('2026-09-29T12:00:00.123456Z'))))->toBeTrue()
        ->and($value->equals(new DateTimeValue(new DateTimeImmutable('2026-09-29T12:00:00.123457Z'))))->toBeFalse()
        ->and($value->equals(new TextValue('2026-09-29T12:00:00.123456Z')))->toBeFalse();
});

it('keeps a list in order and compares it item by item', function (): void {
    $list = new ListValue(new TextValue('a'), new TextValue('b'));

    expect($list->items)->toHaveCount(2)
        ->and($list->equals(new ListValue(new TextValue('a'), new TextValue('b'))))->toBeTrue()
        ->and($list->equals(new ListValue(new TextValue('b'), new TextValue('a'))))->toBeFalse()
        ->and($list->equals(new ListValue(new TextValue('a'))))->toBeFalse()
        ->and(new ListValue(new TextValue('a'))->equals($list))->toBeFalse()
        ->and($list->equals(new ListValue(new TextValue('a'), new TextValue('b'), new TextValue('c'))))->toBeFalse()
        ->and(new ListValue()->equals(new ListValue))->toBeTrue()
        ->and($list->equals(new TextValue('a')))->toBeFalse();
});

it('sorts a map by key, holds each key once and compares it entry by entry', function (): void {
    $map = new MapValue(new MapEntry('_type', new TextValue('span')), new MapEntry('text', new TextValue('Hi')), new MapEntry('marks', new ListValue));

    expect(array_map(static fn (MapEntry $entry): string => $entry->key, $map->entries))->toBe(['_type', 'marks', 'text'])
        ->and($map->get('text'))->toEqual(new TextValue('Hi'))
        ->and($map->get('missing'))->toBeNull()
        ->and($map->equals(new MapValue(new MapEntry('text', new TextValue('Hi')), new MapEntry('marks', new ListValue), new MapEntry('_type', new TextValue('span')))))->toBeTrue()
        ->and($map->equals(new MapValue(new MapEntry('text', new TextValue('Ho')), new MapEntry('marks', new ListValue), new MapEntry('_type', new TextValue('span')))))->toBeFalse()
        ->and($map->equals(new MapValue(new MapEntry('texts', new TextValue('Hi')), new MapEntry('marks', new ListValue), new MapEntry('_type', new TextValue('span')))))->toBeFalse()
        ->and($map->equals(new MapValue(new MapEntry('text', new TextValue('Hi')))))->toBeFalse()
        ->and(new MapValue(new MapEntry('_type', new TextValue('span')))->equals($map))->toBeFalse()
        ->and($map->equals(new GroupValue(new FieldMap)))->toBeFalse()
        ->and(new MapEntry(str_repeat('k', 255), new NullValue)->key)->toHaveLength(255)
        ->and(static fn (): MapValue => new MapValue(new MapEntry('a', new NullValue), new MapEntry('a', new NullValue)))->toThrow(InvalidFieldValue::class, 'The key "a" appears twice in one map value.')
        ->and(static fn (): MapEntry => new MapEntry('', new NullValue))->toThrow(InvalidFieldValue::class, 'A map key is 1 to 255 bytes of UTF-8, got "".')
        ->and(static fn (): MapEntry => new MapEntry(str_repeat('k', 256), new NullValue))->toThrow(InvalidFieldValue::class, 'A map key is 1 to 255 bytes')
        ->and(static fn (): MapEntry => new MapEntry("\xff", new NullValue))->toThrow(InvalidFieldValue::class, 'A map key is 1 to 255 bytes');
});

it('sorts a field map by handle, holds each handle once and tells absent from null', function (): void {
    $map = new FieldMap(field('title', new TextValue('Hi')), field('body', new NullValue), field('count', new IntegerValue(2)));

    expect(array_map(static fn (FieldHandle $handle): string => $handle->value, $map->handles()))->toBe(['body', 'count', 'title'])
        ->and($map->get(new FieldHandle('body')))->toEqual(new NullValue)
        ->and($map->get(new FieldHandle('missing')))->toBeNull()
        ->and($map->isEmpty())->toBeFalse()
        ->and(new FieldMap()->isEmpty())->toBeTrue()
        ->and($map->equals(new FieldMap(field('count', new IntegerValue(2)), field('title', new TextValue('Hi')), field('body', new NullValue))))->toBeTrue()
        ->and($map->equals(new FieldMap(field('count', new IntegerValue(3)), field('title', new TextValue('Hi')), field('body', new NullValue))))->toBeFalse()
        ->and($map->equals(new FieldMap(field('counts', new IntegerValue(2)), field('title', new TextValue('Hi')), field('body', new NullValue))))->toBeFalse()
        ->and($map->equals(new FieldMap(field('title', new TextValue('Hi')))))->toBeFalse()
        ->and(new FieldMap(field('body', new NullValue))->equals($map))->toBeFalse()
        ->and(static fn (): FieldMap => new FieldMap(field('title', new NullValue), field('title', new TextValue('x'))))
        ->toThrow(InvalidFieldValue::class, 'The field "title" appears twice in one field map.');

    $group = new GroupValue(new FieldMap(field('street', new TextValue('Main'))));

    expect($group->equals(new GroupValue(new FieldMap(field('street', new TextValue('Main'))))))->toBeTrue()
        ->and($group->equals(new GroupValue(new FieldMap(field('street', new TextValue('High'))))))->toBeFalse()
        ->and($group->equals(new MapValue(new MapEntry('street', new TextValue('Main')))))->toBeFalse();
});

it('keeps an extender\'s fields under its namespace, next to the owner\'s fields of the same handle', function (): void {
    $own = new FieldMap(field('tax_code', new TextValue('owner')));
    $values = new FieldValues(
        $own,
        new ExtensionFields(new FieldNamespace('shop'), new FieldMap(field('tax_code', new TextValue('shop')))),
        new ExtensionFields(new FieldNamespace('app'), new FieldMap(field('tax_code', new TextValue('app')))),
    );

    expect(array_map(static fn (ExtensionFields $extension): string => $extension->namespace->value, $values->extensions))->toBe(['app', 'shop'])
        ->and($values->own->get(new FieldHandle('tax_code')))->toEqual(new TextValue('owner'))
        ->and($values->extension(new FieldNamespace('app'))?->get(new FieldHandle('tax_code')))->toEqual(new TextValue('app'))
        ->and($values->extension(new FieldNamespace('shop'))?->get(new FieldHandle('tax_code')))->toEqual(new TextValue('shop'))
        ->and($values->extension(new FieldNamespace('acme')))->toBeNull()
        ->and(new FieldValues()->own->isEmpty())->toBeTrue()
        ->and(new FieldValues()->extensions)->toBe([])
        ->and(static fn (): FieldValues => new FieldValues(
            $own,
            new ExtensionFields(new FieldNamespace('app'), new FieldMap),
            new ExtensionFields(new FieldNamespace('app'), new FieldMap),
        ))->toThrow(InvalidFieldValue::class, 'The extension namespace "app" appears twice in one set of field values.');

    $same = new FieldValues(
        new FieldMap(field('tax_code', new TextValue('owner'))),
        new ExtensionFields(new FieldNamespace('app'), new FieldMap(field('tax_code', new TextValue('app')))),
        new ExtensionFields(new FieldNamespace('shop'), new FieldMap(field('tax_code', new TextValue('shop')))),
    );

    expect($values->equals($same))->toBeTrue()
        ->and($values->equals(new FieldValues($own)))->toBeFalse()
        ->and(new FieldValues($own)->equals($values))->toBeFalse()
        ->and($values->equals(new FieldValues(
            new FieldMap(field('tax_code', new TextValue('other'))),
            new ExtensionFields(new FieldNamespace('app'), new FieldMap(field('tax_code', new TextValue('app')))),
            new ExtensionFields(new FieldNamespace('shop'), new FieldMap(field('tax_code', new TextValue('shop')))),
        )))->toBeFalse()
        ->and($values->equals(new FieldValues(
            $own,
            new ExtensionFields(new FieldNamespace('app'), new FieldMap(field('tax_code', new TextValue('app')))),
            new ExtensionFields(new FieldNamespace('shop'), new FieldMap(field('tax_code', new TextValue('other')))),
        )))->toBeFalse()
        ->and($values->equals(new FieldValues(
            $own,
            new ExtensionFields(new FieldNamespace('app'), new FieldMap(field('tax_code', new TextValue('app')))),
            new ExtensionFields(new FieldNamespace('shoq'), new FieldMap(field('tax_code', new TextValue('shop')))),
        )))->toBeFalse();
});

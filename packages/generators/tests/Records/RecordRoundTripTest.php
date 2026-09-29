<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Records;

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
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\ColourChoice;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\DimensionsItem;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\DimensionsSizeChoice;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\ShopProduct;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\ShopProductAppFields;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\ShopProductExt;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\ShopProductRecord;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\SupplierGroup;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Records\ShopProduct\TagsChoice;
use DateTimeImmutable;

/*
 * The generated records convert to and from the kernel's generic field values (PRD 11.12,
 * GUARDRAILS 2.4). The committed golden record of the comprehensive example has every core field
 * type, a group once and repeated, a select field of one option and one of several, rich text and
 * the app's extension, so a round trip through it covers every field type.
 */

function named(string $handle, FieldValue $value): NamedValue
{
    return new NamedValue(new FieldHandle($handle), $value);
}

/**
 * The generic values of a product with a value in every field, the ones the golden record's
 * fromFieldValues() reads and toFieldValues() writes.
 */
function fullProduct(): FieldValues
{
    $block = new MapValue(
        new MapEntry('_type', new TextValue('block')),
        new MapEntry('children', new ListValue(new MapValue(new MapEntry('text', new TextValue('Soft wool.'))))),
        new MapEntry('style', new TextValue('normal')),
    );

    return new FieldValues(
        new FieldMap(
            named('body', new ListValue($block)),
            named('care', new ListValue),
            named('checked_at', new DateTimeValue(new DateTimeImmutable('2026-09-29T10:11:12.345678Z'))),
            named('colour', new TextValue('green')),
            named('dimensions', new ListValue(
                new GroupValue(new FieldMap(named('size', new TextValue('small')), named('width', new IntegerValue(40)))),
                new GroupValue(new FieldMap(named('size', new TextValue('large')), named('width', new NullValue))),
            )),
            named('discontinued', new BooleanValue(false)),
            named('launch_date', new DateValue('2026-03-01')),
            named('name', new TextValue('Scarf')),
            named('price', new DecimalValue('129.5')),
            named('purchase_price', new DecimalValue('-0.25')),
            named('stock', new IntegerValue(0)),
            named('summary', new TextValue('')),
            named('supplier', new GroupValue(new FieldMap(
                named('company', new TextValue('Wool & Co')),
                named('notes', new TextValue('Ships on Mondays.')),
                named('website', new NullValue),
            ))),
            named('support_email', new TextValue('help@example.com')),
            named('tags', new ListValue(new TextValue('sale'), new TextValue('eco'))),
            named('weight', new IntegerValue(180)),
        ),
        new ExtensionFields(new FieldNamespace('app'), new FieldMap(
            named('name', new TextValue('SCARF-01')),
            named('tax_code', new TextValue('DK25')),
        )),
    );
}

/**
 * The generic values of a product whose optional fields all hold NullValue.
 */
function sparseProduct(): FieldValues
{
    $fields = [];

    foreach (['body', 'care', 'checked_at', 'dimensions', 'discontinued', 'launch_date', 'purchase_price', 'stock', 'summary', 'supplier', 'support_email', 'tags', 'weight'] as $handle) {
        $fields[] = named($handle, new NullValue);
    }

    return new FieldValues(
        new FieldMap(named('colour', new TextValue('red')), named('name', new TextValue('Hat')), named('price', new DecimalValue('10')), ...$fields),
        new ExtensionFields(new FieldNamespace('app'), new FieldMap(named('name', new NullValue), named('tax_code', new NullValue))),
    );
}

it('covers every core field type of the blueprint schema with the golden record', function (): void {
    $types = [];

    foreach (ComprehensiveExample::compile()->types[0]->fields as $field) {
        $types[] = $field->type;
    }

    expect(array_values(array_unique($types)))->toEqualCanonicalizing(['boolean', 'date', 'datetime', 'decimal', 'group', 'integer', 'long_text', 'rich_text', 'select', 'text']);
});

it('round-trips the generic values of every field type through the record', function (FieldValues $values): void {
    $record = ShopProduct::fromFieldValues($values);

    expect($record->toFieldValues()->equals($values))->toBeTrue()
        ->and(ShopProduct::fromFieldValues($record->toFieldValues()))->toEqual($record);
})->with(function (): iterable {
    yield 'every field with a value' => [fullProduct()];
    yield 'every optional field null' => [sparseProduct()];
});

it('reads each field type into its PHP value', function (): void {
    $record = ShopProduct::fromFieldValues(fullProduct());

    expect($record)->toBeInstanceOf(ShopProductRecord::class)
        ->and($record->name)->toBe('Scarf')
        ->and($record->summary)->toBe('')
        ->and($record->stock)->toBe(0)
        ->and($record->price)->toBe('129.5')
        ->and($record->purchasePrice)->toBe('-0.25')
        ->and($record->discontinued)->toBeFalse()
        ->and($record->launchDate?->format(DATE_RFC3339_EXTENDED))->toBe('2026-03-01T00:00:00.000+00:00')
        ->and($record->checkedAt?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-29T10:11:12.345678+00:00')
        ->and($record->colour)->toBe(ColourChoice::Green)
        ->and($record->tags)->toBe([TagsChoice::Sale, TagsChoice::Eco])
        ->and($record->body?->items)->toHaveCount(1)
        ->and($record->care?->items)->toBe([])
        ->and($record->supplier)->toEqual(new SupplierGroup('Wool & Co', 'Ships on Mondays.', null))
        ->and($record->dimensions)->toEqual([new DimensionsItem(DimensionsSizeChoice::Small, 40), new DimensionsItem(DimensionsSizeChoice::Large, null)])
        ->and($record->ext->app)->toEqual(new ShopProductAppFields('SCARF-01', 'DK25'));
});

it('writes a record built in PHP and reads the same record back', function (): void {
    $record = new ShopProduct(
        body: null,
        care: new ListValue,
        checkedAt: new DateTimeImmutable('2026-01-02T03:04:05.000006+00:00'),
        colour: ColourChoice::Blue,
        dimensions: [],
        discontinued: true,
        launchDate: new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        name: 'Mitten',
        price: '0.5',
        purchasePrice: null,
        stock: 12,
        summary: 'Warm.',
        supplier: new SupplierGroup('Knit', null, 'https://example.com'),
        supportEmail: null,
        tags: [TagsChoice::New],
        weight: null,
        ext: new ShopProductExt(new ShopProductAppFields(null, 'X1')),
    );

    expect(ShopProduct::fromFieldValues($record->toFieldValues()))->toEqual($record)
        ->and($record->toFieldValues()->own->get(new FieldHandle('launch_date')))->toEqual(new DateValue('2026-01-02'))
        ->and($record->toFieldValues()->extension(new FieldNamespace('app'))?->get(new FieldHandle('tax_code')))->toEqual(new TextValue('X1'));
});

it('reads a product without the extender\'s fields as an extension of nulls', function (): void {
    $values = new FieldValues(new FieldMap(
        named('colour', new TextValue('red')),
        named('name', new TextValue('Hat')),
        named('price', new DecimalValue('10')),
    ));

    expect(ShopProduct::fromFieldValues($values)->ext->app)->toEqual(new ShopProductAppFields(null, null));
});

it('refuses generic values that do not fit the type, naming the field', function (FieldValues $values, string $message): void {
    expect(fn (): ShopProduct => ShopProduct::fromFieldValues($values))->toThrow(InvalidFieldValue::class, $message);
})->with([
    'a required field absent' => [
        fn (): FieldValues => new FieldValues(new FieldMap(named('colour', new TextValue('red')), named('price', new DecimalValue('1')))),
        'The field "name" is required and holds no value.',
    ],
    'a required field null' => [
        fn (): FieldValues => new FieldValues(new FieldMap(named('colour', new TextValue('red')), named('name', new NullValue), named('price', new DecimalValue('1')))),
        'The field "name" is required and holds no value.',
    ],
    'a value of another kind' => [
        fn (): FieldValues => new FieldValues(new FieldMap(named('colour', new TextValue('red')), named('name', new IntegerValue(1)), named('price', new DecimalValue('1')))),
        'The field "name" holds IntegerValue, expected TextValue.',
    ],
    'an option the field does not have' => [
        fn (): FieldValues => new FieldValues(new FieldMap(named('colour', new TextValue('purple')), named('name', new TextValue('Hat')), named('price', new DecimalValue('1')))),
        'The field "colour" holds "purple", which is not one of its options.',
    ],
    'a nested required field null' => [
        fn (): FieldValues => new FieldValues(new FieldMap(
            named('colour', new TextValue('red')),
            named('name', new TextValue('Hat')),
            named('price', new DecimalValue('1')),
            named('dimensions', new ListValue(new GroupValue(new FieldMap(named('size', new NullValue))))),
        )),
        'The field "dimensions.0.size" is required and holds no value.',
    ],
    'an extension field of another kind' => [
        fn (): FieldValues => new FieldValues(
            new FieldMap(named('colour', new TextValue('red')), named('name', new TextValue('Hat')), named('price', new DecimalValue('1'))),
            new ExtensionFields(new FieldNamespace('app'), new FieldMap(named('tax_code', new BooleanValue(true)))),
        ),
        'The field "tax_code" holds BooleanValue, expected TextValue.',
    ],
]);

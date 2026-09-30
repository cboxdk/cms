<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\TypeTables;

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
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use DateTimeImmutable;

/*
 * The column form of a type's fields in its type table (PRD 11.6): decode() reads a row as PDO
 * returns it into field values, encode() gives the column values of field values, and a row the
 * kernel wrote reads back as the values it was written from.
 */

/**
 * @param  list<FieldDefinition>  $fields
 */
function columnField(string $handle, string $fieldType, string $type, ?string $namespace = null, bool $encrypted = false, array $fields = []): FieldDefinition
{
    return new FieldDefinition(
        $namespace === null ? null : new FieldNamespace($namespace),
        new FieldHandle($handle),
        $fieldType,
        $encrypted ? ClassificationAccess::Confidential : ClassificationAccess::Public,
        ! $encrypted,
        $encrypted,
        false,
        false,
        false,
        new ColumnDefinition($namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle, $type, false, []),
        $fields,
    );
}

function nestedField(string $handle, string $fieldType): FieldDefinition
{
    return new FieldDefinition(null, new FieldHandle($handle), $fieldType, ClassificationAccess::Public, true, false, false, false, false, null);
}

function columnsType(): TypeDefinition
{
    return new TypeDefinition(
        TypeId::fromString('0192a0c0-0000-7000-8000-00000000f002'),
        new TypeName('shop:thing'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [new ExtensionVersion(new FieldNamespace('acme'), 1)],
        [
            columnField('title', 'text', 'text'),
            columnField('summary', 'long_text', 'text'),
            columnField('size', 'select', 'text'),
            columnField('tags', 'select', 'text[]'),
            columnField('stock', 'integer', 'bigint'),
            columnField('price', 'decimal', 'numeric(10, 2)'),
            columnField('active', 'boolean', 'boolean'),
            columnField('launch', 'date', 'date'),
            columnField('checked_at', 'datetime', 'timestamptz'),
            columnField('body', 'rich_text', 'jsonb'),
            columnField('supplier', 'group', 'jsonb', fields: [
                nestedField('name', 'text'),
                nestedField('share', 'decimal'),
                nestedField('count', 'integer'),
                nestedField('verified', 'boolean'),
                nestedField('since', 'date'),
                nestedField('seen_at', 'datetime'),
                nestedField('kinds', 'select'),
                nestedField('note', 'rich_text'),
                nestedField('contact', 'group'),
            ]),
            columnField('secret', 'text', 'bytea', encrypted: true),
            columnField('code', 'text', 'text', namespace: 'acme'),
        ],
    );
}

function named(string $handle, FieldValue $value): NamedValue
{
    return new NamedValue(new FieldHandle($handle), $value);
}

function fullValues(): FieldValues
{
    $block = new MapValue(
        new MapEntry('_type', new TextValue('block')),
        new MapEntry('children', new ListValue(new MapValue(new MapEntry('_type', new TextValue('span')), new MapEntry('text', new TextValue('Hi "you"'))))),
        new MapEntry('level', new IntegerValue(1)),
        new MapEntry('draft', new BooleanValue(false)),
        new MapEntry('extra', new NullValue),
    );

    return new FieldValues(
        new FieldMap(
            named('title', new TextValue('Chair, "big" \\ blue')),
            named('summary', new TextValue('Æble ø')),
            named('size', new TextValue('large')),
            named('tags', new ListValue(new TextValue('red'), new TextValue('a,b'), new TextValue('say "hi"'), new TextValue('back\\slash'), new TextValue('NULL'))),
            named('stock', new IntegerValue(-42)),
            named('price', new DecimalValue('10.50')),
            named('active', new BooleanValue(true)),
            named('launch', new DateValue('2026-03-10')),
            named('checked_at', new DateTimeValue(new DateTimeImmutable('2026-03-10T12:30:00.123456+02:00'))),
            named('body', new ListValue($block)),
            named('supplier', new GroupValue(new FieldMap(
                named('name', new TextValue('Acme')),
                named('share', new DecimalValue('-0.25')),
                named('count', new IntegerValue(3)),
                named('verified', new BooleanValue(true)),
                named('since', new DateValue('2020-01-31')),
                named('seen_at', new DateTimeValue(new DateTimeImmutable('2026-01-01T00:00:00Z'))),
                named('kinds', new ListValue(new TextValue('wood'), new TextValue('metal'))),
                named('note', new ListValue($block)),
                named('contact', new NullValue),
            ))),
            named('secret', new NullValue),
        ),
        new ExtensionFields(new FieldNamespace('acme'), new FieldMap(named('code', new TextValue('X-1')))),
    );
}

it('reads back the values a row was written from, every field type, a group and an extension field included', function (): void {
    $row = TypeTableColumns::encode(columnsType(), fullValues());
    $read = TypeTableColumns::decode(columnsType(), $row);
    $expected = new FieldValues(
        new FieldMap(...array_values(array_filter(fullValues()->own->fields, static fn (NamedValue $field): bool => $field->handle->value !== 'secret'))),
        ...fullValues()->extensions,
    );

    expect($read->equals($expected))->toBeTrue()
        ->and($row['secret'])->toBeNull()
        ->and($row['ext__acme__code'])->toBe('X-1')
        ->and($row['price'])->toBe('10.5')
        ->and($row['stock'])->toBe(-42)
        ->and($row['active'])->toBeTrue()
        ->and($row['checked_at'])->toBe('2026-03-10 10:30:00.123456+00:00')
        ->and($row['tags'])->toBe('{"red","a,b","say \"hi\"","back\\\\slash","NULL"}');
});

it('writes a group as a JSON object of its fields and a repeated group as a list of them', function (): void {
    $group = static fn (string $name): GroupValue => new GroupValue(new FieldMap(named('name', new TextValue($name)), named('share', new DecimalValue('1.50'))));
    $one = TypeTableColumns::encode(columnsType(), new FieldValues(new FieldMap(named('supplier', $group('a')))));
    $many = TypeTableColumns::encode(columnsType(), new FieldValues(new FieldMap(named('supplier', new ListValue($group('a'), $group('b'))))));

    expect($one)->toBe(['supplier' => '{"name":"a","share":"1.5"}'])
        ->and($many)->toBe(['supplier' => '[{"name":"a","share":"1.5"},{"name":"b","share":"1.5"}]'])
        ->and(TypeTableColumns::decode(columnsType(), [...array_fill_keys(['title', 'summary', 'size', 'tags', 'stock', 'price', 'active', 'launch', 'checked_at', 'body', 'ext__acme__code'], null), ...$many])->own->get(new FieldHandle('supplier')))
        ->toEqual(new ListValue($group('a'), $group('b')));
});

it('leaves out the fields the values do not hold, and writes null for a NullValue', function (): void {
    expect(TypeTableColumns::encode(columnsType(), new FieldValues(new FieldMap(named('title', new NullValue), named('stock', new IntegerValue(0))))))
        ->toBe(['stock' => 0, 'title' => null]);
});

/**
 * A row of columnsType() as PDO returns it, every column null but the ones given.
 *
 * @param  array<array-key, mixed>  $columns
 * @return array<array-key, mixed>
 */
function pdoRow(array $columns): array
{
    return [...array_fill_keys(['title', 'summary', 'size', 'tags', 'stock', 'price', 'active', 'launch', 'checked_at', 'body', 'supplier', 'secret', 'ext__acme__code'], null), ...$columns];
}

it('reads a row as Postgres returns it through PDO', function (): void {
    $read = TypeTableColumns::decode(columnsType(), pdoRow([
        'tags' => '{red,"a b",NULL,"x\\"y"}',
        'stock' => '12',
        'price' => '3.10',
        'active' => 'f',
        'checked_at' => '2026-03-10 12:00:00+05:30',
        'secret' => '\\xdeadbeef',
    ]));

    expect($read->own->get(new FieldHandle('tags')))->toEqual(new ListValue(new TextValue('red'), new TextValue('a b'), new NullValue, new TextValue('x"y')))
        ->and($read->own->get(new FieldHandle('stock')))->toEqual(new IntegerValue(12))
        ->and($read->own->get(new FieldHandle('price')))->toEqual(new DecimalValue('3.1'))
        ->and($read->own->get(new FieldHandle('active')))->toEqual(new BooleanValue(false))
        ->and($read->own->get(new FieldHandle('checked_at'))?->equals(new DateTimeValue(new DateTimeImmutable('2026-03-10T06:30:00Z'))))->toBeTrue()
        ->and($read->own->get(new FieldHandle('title')))->toEqual(new NullValue)
        ->and($read->own->get(new FieldHandle('secret')))->toBeNull()
        ->and($read->extension(new FieldNamespace('acme'))?->get(new FieldHandle('code')))->toEqual(new NullValue);
});

it('reads an empty text array as an empty list', function (): void {
    expect(TypeTableColumns::decode(columnsType(), pdoRow(['tags' => '{}']))->own->get(new FieldHandle('tags')))->toEqual(new ListValue);
});

it('refuses a row that lacks a column', function (): void {
    $row = pdoRow([]);
    unset($row['price']);

    expect(fn (): FieldValues => TypeTableColumns::decode(columnsType(), $row))->toThrow(UnreadableTypeTable::class, 'The row of the type table has no column price.');
});

it('refuses a value that does not have its column\'s form', function (array $columns, string $message): void {
    expect(fn (): FieldValues => TypeTableColumns::decode(columnsType(), pdoRow($columns)))->toThrow(UnreadableTypeTable::class, $message);
})->with([
    'text that is a number' => [['title' => 5], 'The column title of the type table does not hold text.'],
    'an integer that is text' => [['stock' => 'many'], 'The column stock of the type table does not hold an integer.'],
    'an integer that overflows' => [['stock' => '99999999999999999999'], 'does not hold an integer'],
    'an integer with a leading zero' => [['stock' => '012'], 'does not hold an integer'],
    'a boolean that is text' => [['active' => 'yes'], 'The column active of the type table does not hold a boolean.'],
    'a decimal that is not one' => [['price' => '1e3'], 'The column price of the type table does not hold a valid value'],
    'a date that is not one' => [['launch' => '2026-02-30'], 'The column launch of the type table does not hold a valid value'],
    'a date-time without an offset' => [['checked_at' => '2026-03-10 12:00:00'], 'The column checked_at of the type table does not hold a date-time with its offset.'],
    'a text array without braces' => [['tags' => 'red,blue'], 'The column tags of the type table does not hold a text array.'],
    'a text array with an open quote' => [['tags' => '{"red}'], 'does not hold a text array'],
    'a text array with an empty element' => [['tags' => '{red,,blue}'], 'does not hold a text array'],
    'a text array with a trailing comma' => [['tags' => '{red,}'], 'does not hold a text array'],
    'a text array with a stray quote' => [['tags' => '{"red"blue}'], 'does not hold a text array'],
    'a text array with a nested array' => [['tags' => '{{red}}'], 'does not hold a text array'],
    'JSON that is not JSON' => [['body' => '[{'], 'The column body of the type table does not hold a JSON document.'],
    'rich text that is not a list' => [['body' => '{"_type":"block"}'], 'The column body of the type table does not hold a list of Portable Text blocks.'],
    'rich text with a fraction' => [['body' => '[1.5]'], 'does not hold Portable Text blocks'],
    'a group that is text' => [['supplier' => '"acme"'], 'The column supplier of the type table does not hold an object or a list of objects.'],
    'a repeated group of text' => [['supplier' => '["acme"]'], 'The column supplier of the type table does not hold a list of objects.'],
    'a group with a field it does not have' => [['supplier' => '{"colour":"red"}'], 'The column supplier of the type table does not hold only the fields of its group, not colour.'],
    'a nested integer that is text' => [['supplier' => '{"count":"3"}'], 'The column supplier.count of the type table does not hold an integer.'],
    'a nested decimal that is a number' => [['supplier' => '{"share":2}'], 'The column supplier.share of the type table does not hold text.'],
    'a nested boolean that is a number' => [['supplier' => '{"verified":1}'], 'The column supplier.verified of the type table does not hold a boolean.'],
    'a nested date-time without an offset' => [['supplier' => '{"seen_at":"2026-01-01T00:00:00"}'], 'The column supplier.seen_at of the type table does not hold a date-time with its offset.'],
    'a nested option list of numbers' => [['supplier' => '{"kinds":[1]}'], 'The column supplier.kinds of the type table does not hold text.'],
    'a nested text that is a list' => [['supplier' => '{"name":["a"]}'], 'The column supplier.name of the type table does not hold text.'],
]);

it('reads the nested fields of a group from their JSON forms', function (): void {
    $supplier = TypeTableColumns::decode(columnsType(), pdoRow(['supplier' => '{"name":null,"share":"2","kinds":"wood","contact":{},"note":[]}']))->own->get(new FieldHandle('supplier'));

    expect($supplier)->toEqual(new GroupValue(new FieldMap(
        named('contact', new GroupValue(new FieldMap)),
        named('kinds', new TextValue('wood')),
        named('name', new NullValue),
        named('note', new ListValue),
        named('share', new DecimalValue('2')),
    )));
});

it('refuses a field type that has no column form', function (): void {
    $type = new TypeDefinition(
        TypeId::fromString('0192a0c0-0000-7000-8000-00000000f003'),
        new TypeName('shop:odd'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [new ExtensionVersion(new FieldNamespace('acme'), 1)],
        [columnField('colour', 'acme:colour', 'text'), columnField('extra', 'group', 'jsonb', fields: [nestedField('shade', 'acme:colour')])],
    );

    expect(fn (): FieldValues => TypeTableColumns::decode($type, ['colour' => 'red', 'extra' => null]))->toThrow(UnreadableTypeTable::class, 'The column colour holds a field of the type acme:colour, which has no column form in a type table.')
        ->and(fn (): FieldValues => TypeTableColumns::decode($type, ['colour' => null, 'extra' => '{"shade":"red"}']))->toThrow(UnreadableTypeTable::class, 'The column extra.shade holds a field of the type acme:colour');
});

it('refuses to write a value that does not fit its field, or one for an encrypted field', function (FieldValues $values, string $message): void {
    expect(fn (): array => TypeTableColumns::encode(columnsType(), $values))->toThrow(UnreadableTypeTable::class, $message);
})->with([
    'an integer for text' => [new FieldValues(new FieldMap(named('title', new IntegerValue(1)))), 'The column title of the type table does not hold a Cbox\Cms\Contracts\Fields\IntegerValue for a field of the type text.'],
    'a list for a select of one option' => [new FieldValues(new FieldMap(named('size', new ListValue(new TextValue('a'))))), 'does not hold a Cbox\Cms\Contracts\Fields\ListValue for a field of the type select'],
    'a list of integers for a select of several' => [new FieldValues(new FieldMap(named('tags', new ListValue(new IntegerValue(1))))), 'The column tags of the type table does not hold a list of option values.'],
    'text for an integer' => [new FieldValues(new FieldMap(named('stock', new TextValue('1')))), 'for a field of the type integer'],
    'text for a group' => [new FieldValues(new FieldMap(named('supplier', new TextValue('a')))), 'The column supplier of the type table does not hold a group.'],
    'a list of text for a repeated group' => [new FieldValues(new FieldMap(named('supplier', new ListValue(new TextValue('a'))))), 'does not hold a list of groups'],
    'a group field it does not have' => [new FieldValues(new FieldMap(named('supplier', new GroupValue(new FieldMap(named('colour', new TextValue('red'))))))), 'does not hold only the fields of its group, not colour'],
    'a nested value of the wrong kind' => [new FieldValues(new FieldMap(named('supplier', new GroupValue(new FieldMap(named('count', new TextValue('3'))))))), 'The column supplier.count of the type table does not hold a Cbox\Cms\Contracts\Fields\TextValue for a field of the type integer.'],
    'a nested option list of integers' => [new FieldValues(new FieldMap(named('supplier', new GroupValue(new FieldMap(named('kinds', new ListValue(new IntegerValue(1)))))))), 'The column supplier.kinds of the type table does not hold a list of option values.'],
    'rich text with a decimal' => [new FieldValues(new FieldMap(named('body', new ListValue(new DecimalValue('1'))))), 'The column body of the type table does not hold Portable Text blocks'],
    'a value for an encrypted field' => [new FieldValues(new FieldMap(named('secret', new TextValue('x')))), 'The column secret holds an encrypted field, and the kernel holds no key to write its ciphertext'],
]);

it('binds a comparable value as the parameter Postgres reads for its column', function (): void {
    expect(TypeTableColumns::binding('c', new TextValue('a')))->toBe('a')
        ->and(TypeTableColumns::binding('c', new IntegerValue(7)))->toBe(7)
        ->and(TypeTableColumns::binding('c', new DecimalValue('1.50')))->toBe('1.5')
        ->and(TypeTableColumns::binding('c', new BooleanValue(false)))->toBeFalse()
        ->and(TypeTableColumns::binding('c', new DateValue('2026-03-10')))->toBe('2026-03-10')
        ->and(TypeTableColumns::binding('c', new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00.5+01:00'))))->toBe('2026-03-10 11:00:00.500000+00:00')
        ->and(fn (): bool|int|string => TypeTableColumns::binding('c', new NullValue))->toThrow(UnreadableTypeTable::class, 'The column c of the type table does not hold a value that compares');
});

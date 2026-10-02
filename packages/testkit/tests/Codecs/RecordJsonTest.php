<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
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
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Testkit\Codecs\Boundary\RecordJson;
use DateTimeImmutable;
use stdClass;

/*
 * The JSON form FakeRecordCodecs writes a value of each core field type in, as the generated codecs
 * write it, and the values that do not fit their field, which it refuses.
 */

const RECORD_JSON_TYPE = '0198d2a4-5c3e-7a41-9b2f-000000000301';

/**
 * A top-level field of the core field type, with the nested fields of a group.
 *
 * @param  list<FieldDefinition>  $fields
 */
function recordJsonField(string $type, array $fields = [], string $handle = 'value'): FieldDefinition
{
    return new FieldDefinition(null, new FieldHandle($handle), $type, ClassificationAccess::Public, true, false, false, false, false, null, $fields);
}

/**
 * The value as the record's JSON writes it.
 */
function recordJsonOf(FieldDefinition $field, FieldValue $value): string
{
    $record = new stdClass;
    $record->value = RecordJson::value(TypeId::fromString(RECORD_JSON_TYPE), $field, $value);

    return RecordJson::encode(TypeId::fromString(RECORD_JSON_TYPE), $record);
}

it('writes a value of each core field type as the generated codecs do', function (string $type, FieldValue $value, string $json): void {
    expect(recordJsonOf(recordJsonField($type), $value))->toBe('{"value":'.$json.'}');
})->with([
    'text, with slashes and letters outside ASCII as they are' => ['text', new TextValue('Ærø/København'), '"Ærø/København"'],
    'long text' => ['long_text', new TextValue('A body'), '"A body"'],
    'a select\'s option' => ['select', new TextValue('red'), '"red"'],
    'a select\'s options' => ['select', new ListValue(new TextValue('red'), new TextValue('blue')), '["red","blue"]'],
    'an integer' => ['integer', new IntegerValue(42), '42'],
    'a decimal as its string' => ['decimal', new DecimalValue('12.5'), '"12.5"'],
    'a boolean' => ['boolean', new BooleanValue(true), 'true'],
    'a date' => ['date', new DateValue('2026-10-01'), '"2026-10-01"'],
    'a date-time in UTC with microseconds' => ['datetime', new DateTimeValue(new DateTimeImmutable('2026-10-01T14:00:00.123456+02:00')), '"2026-10-01T12:00:00.123456Z"'],
    'null' => ['integer', new NullValue, 'null'],
    'rich text, its blocks with their keys sorted' => ['rich_text', new ListValue(new MapValue(
        new MapEntry('style', new TextValue('normal')),
        new MapEntry('_type', new TextValue('block')),
        new MapEntry('children', new ListValue(new TextValue('Hi'), new IntegerValue(2), new BooleanValue(false), new DecimalValue('1.5'))),
    )), '[{"_type":"block","children":["Hi",2,false,null],"style":"normal"}]'],
]);

it('writes a group as an object with its keys sorted, and a repeated group as a list of them', function (): void {
    $group = recordJsonField('group', [recordJsonField('text', handle: 'name'), recordJsonField('integer', handle: 'age')]);
    $value = new GroupValue(new FieldMap(new NamedValue(new FieldHandle('name'), new TextValue('Ann')), new NamedValue(new FieldHandle('age'), new IntegerValue(30))));

    expect(recordJsonOf($group, $value))->toBe('{"value":{"age":30,"name":"Ann"}}')
        ->and(recordJsonOf($group, new ListValue($value, $value)))->toBe('{"value":[{"age":30,"name":"Ann"},{"age":30,"name":"Ann"}]}');
});

it('refuses a value that does not fit its field', function (string $type, FieldValue $value, string $refusal): void {
    expect(static fn (): string => recordJsonOf(recordJsonField($type, [recordJsonField('text', handle: 'name')]), $value))
        ->toThrow(InvalidRecordDocument::class, $refusal);
})->with([
    'an integer for a text' => ['text', new IntegerValue(1), 'value is a field of the type text and cannot hold a '.IntegerValue::class],
    'a list for a text' => ['text', new ListValue(new TextValue('a')), 'cannot hold a '.ListValue::class],
    'a text for an integer' => ['integer', new TextValue('1'), 'cannot hold a '.TextValue::class],
    'a text for a decimal' => ['decimal', new TextValue('1.5'), 'cannot hold a '.TextValue::class],
    'a text for a boolean' => ['boolean', new TextValue('yes'), 'cannot hold a '.TextValue::class],
    'a text for a date' => ['date', new TextValue('2026-10-01'), 'cannot hold a '.TextValue::class],
    'a text for a date-time' => ['datetime', new TextValue('2026-10-01T12:00:00Z'), 'cannot hold a '.TextValue::class],
    'a text for rich text' => ['rich_text', new TextValue('Hi'), 'cannot hold a '.TextValue::class],
    'a text for a group' => ['group', new TextValue('Ann'), 'cannot hold a '.TextValue::class],
    'a boolean for a select' => ['select', new BooleanValue(true), 'cannot hold a '.BooleanValue::class],
    'an integer for a list of groups' => ['group', new ListValue(new IntegerValue(1)), 'value holds a list of groups'],
    'a field the group does not have' => ['group', new GroupValue(new FieldMap(new NamedValue(new FieldHandle('age'), new IntegerValue(1)))), 'value has no field age'],
    'a list for an integer' => ['integer', new ListValue(new IntegerValue(1)), 'cannot hold a '.ListValue::class],
    'a list for a date' => ['date', new ListValue(new DateValue('2026-10-01')), 'cannot hold a '.ListValue::class],
]);

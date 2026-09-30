<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

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
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use DateTimeImmutable;
use LogicException;

/*
 * A payload read back into field values (PRD 4.1, 5.6): StoredContent::fields() is the inverse of
 * StoredContent::payload() for a type in the schema version the payload was written under, the
 * owner's fields by handle and each extender's under `ext`, so a release writes the released row
 * from the revision it releases. A payload that is not such an object of the type's fields is a
 * fault of the kernel, which wrote it, and is refused.
 */

/**
 * @param  list<FieldDefinition>  $nested
 */
function payloadField(string $handle, string $fieldType, string $columnType, ?string $namespace = null, array $nested = []): FieldDefinition
{
    return new FieldDefinition(
        $namespace === null ? null : new FieldNamespace($namespace),
        new FieldHandle($handle),
        $fieldType,
        ClassificationAccess::Public,
        true,
        false,
        false,
        false,
        false,
        new ColumnDefinition($namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle, $columnType, false, []),
        $nested,
    );
}

function payloadNested(string $handle, string $fieldType): FieldDefinition
{
    return new FieldDefinition(null, new FieldHandle($handle), $fieldType, ClassificationAccess::Public, true, false, false, false, false, null);
}

function payloadType(): TypeDefinition
{
    return new TypeDefinition(
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007d1'),
        new TypeName('test:payload'),
        2,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [new ExtensionVersion(new FieldNamespace('acme'), 1)],
        [
            payloadField('title', 'text', 'text'),
            payloadField('minutes', 'integer', 'bigint'),
            payloadField('price', 'decimal', 'numeric(8,2)'),
            payloadField('featured', 'boolean', 'boolean'),
            payloadField('day', 'date', 'date'),
            payloadField('at', 'datetime', 'timestamptz'),
            payloadField('topics', 'select', 'text[]'),
            payloadField('empty', 'text', 'text'),
            payloadField('sources', 'group', 'jsonb', nested: [payloadNested('label', 'text'), payloadNested('rank', 'integer')]),
            payloadField('code', 'text', 'text', 'acme'),
        ],
    );
}

/**
 * @param  array<string, FieldValue>  $values
 */
function payloadMap(array $values): FieldMap
{
    return new FieldMap(...array_map(
        static fn (string $handle, FieldValue $value): NamedValue => new NamedValue(new FieldHandle($handle), $value),
        array_keys($values),
        array_values($values),
    ));
}

it('reads every kind of field and the extension fields back as the payload was written', function (): void {
    $fields = new FieldValues(
        payloadMap([
            'title' => new TextValue('Harbour "opens"'),
            'minutes' => new IntegerValue(4),
            'price' => new DecimalValue('12.50'),
            'featured' => new BooleanValue(false),
            'day' => new DateValue('2026-03-09'),
            'at' => new DateTimeValue(new DateTimeImmutable('2026-03-10T11:59:58.250000Z')),
            'topics' => new ListValue(new TextValue('science'), new TextValue('culture')),
            'empty' => new NullValue,
            'sources' => new ListValue(new GroupValue(payloadMap(['label' => new TextValue('A source'), 'rank' => new IntegerValue(1)]))),
        ]),
        new ExtensionFields(new FieldNamespace('acme'), payloadMap(['code' => new TextValue('X-1')])),
    );

    $read = StoredContent::fields(payloadType(), StoredContent::payload($fields));

    expect($read->equals($fields))->toBeTrue()
        ->and($read->own->get(new FieldHandle('at')))->toEqual(new DateTimeValue(new DateTimeImmutable('2026-03-10T11:59:58.250000Z')))
        ->and($read->extension(new FieldNamespace('acme'))?->get(new FieldHandle('code')))->toEqual(new TextValue('X-1'));
});

it('reads a payload that leaves fields out, and one without extension fields', function (): void {
    $fields = new FieldValues(payloadMap(['title' => new TextValue('Only a title')]));

    $read = StoredContent::fields(payloadType(), StoredContent::payload($fields));

    expect($read->equals($fields))->toBeTrue()
        ->and($read->extensions)->toBe([])
        ->and($read->own->get(new FieldHandle('minutes')))->toBeNull();
});

it('refuses a payload that is not a JSON object of the type\'s fields', function (string $payload, string $message): void {
    expect(static fn (): FieldValues => StoredContent::fields(payloadType(), $payload))->toThrow(LogicException::class, $message);
})->with([
    'not JSON' => ['{"title":', 'A payload of test:payload is not a JSON object'],
    'a list' => ['["title"]', 'A payload of test:payload is not a JSON object'],
    'a field the type does not declare' => ['{"title":"A","colour":"green","size":3}', 'A payload of test:payload holds "colour", "size", which the type does not declare.'],
    'an extension field the type does not declare' => ['{"ext":{"acme":{"colour":"green"}}}', 'holds "colour", which the type does not declare in the namespace acme.'],
    'an owner\'s field as an extension' => ['{"ext":{"other":{"title":"A"}}}', 'holds "title", which the type does not declare in the namespace other.'],
    'extension fields that are not an object' => ['{"ext":["acme"]}', 'The extension fields of a payload of test:payload are not a JSON object.'],
    'a namespace whose fields are not an object' => ['{"ext":{"acme":"X-1"}}', 'The fields of the namespace "acme" in a payload of test:payload are not a JSON object.'],
    'a value of the wrong kind' => ['{"minutes":"four"}', 'A payload of test:payload cannot be read'],
    'an invalid value' => ['{"day":"yesterday"}', 'A payload of test:payload cannot be read'],
]);

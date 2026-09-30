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
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use DateTimeImmutable;
use LogicException;

/*
 * The stored forms of a revision's fields (PRD 4.1, 11.6): the payload, the field-values input form
 * as JSON, and the type table's row, a value per top-level field's column in the form Postgres
 * takes as the column's type, NULL for a field left out or holding no value.
 */

function storedField(string $handle, string $type, bool $encrypted = false, ?string $namespace = null): FieldDefinition
{
    $column = $namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle;

    return new FieldDefinition(
        $namespace === null ? null : new FieldNamespace($namespace),
        new FieldHandle($handle),
        'text',
        $encrypted ? ClassificationAccess::Confidential : ClassificationAccess::Public,
        ! $encrypted,
        $encrypted,
        false,
        false,
        false,
        new ColumnDefinition($column, $type, false, []),
    );
}

function storedType(FieldDefinition ...$fields): TypeDefinition
{
    return new TypeDefinition(
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000005d1'),
        new TypeName('test:stored'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [new ExtensionVersion(new FieldNamespace('acme'), 1)],
        array_values($fields),
    );
}

/**
 * @param  array<string, FieldValue>  $values
 */
function storedMap(array $values): FieldMap
{
    return new FieldMap(...array_map(static fn (string $handle): NamedValue => new NamedValue(new FieldHandle($handle), $values[$handle]), array_keys($values)));
}

it('gives every column the form Postgres takes as its type', function (): void {
    $type = storedType(
        storedField('title', 'text'),
        storedField('minutes', 'bigint'),
        storedField('price', 'numeric(10, 2)'),
        storedField('flag', 'boolean'),
        storedField('off', 'boolean'),
        storedField('day', 'date'),
        storedField('at', 'timestamptz'),
        storedField('tags', 'text[]'),
        storedField('body', 'jsonb'),
        storedField('meta', 'jsonb'),
        storedField('empty', 'text'),
        storedField('absent', 'text'),
        storedField('code', 'text', namespace: 'acme'),
    );
    $fields = new FieldValues(storedMap([
        'title' => new TextValue('Grøn "tekst"'),
        'minutes' => new IntegerValue(12),
        'price' => new DecimalValue('012.50'),
        'flag' => new BooleanValue(true),
        'off' => new BooleanValue(false),
        'day' => new DateValue('2026-03-09'),
        'at' => new DateTimeValue(new DateTimeImmutable('2026-03-10T13:00:00.5+01:00')),
        'tags' => new ListValue(new TextValue('a "b"'), new TextValue('c\\d'), new TextValue('e,f')),
        'body' => new ListValue(new MapValue(new MapEntry('text', new TextValue('Hej / there')))),
        'meta' => new GroupValue(storedMap(['size' => new IntegerValue(3)])),
        'empty' => new NullValue,
    ]), new ExtensionFields(new FieldNamespace('acme'), storedMap(['code' => new TextValue('X-1')])));

    expect(StoredContent::columns($type, $fields))->toBe([
        'absent' => null,
        'at' => '2026-03-10 12:00:00.500000+00:00',
        'body' => '[{"text":"Hej / there"}]',
        'day' => '2026-03-09',
        'empty' => null,
        'ext__acme__code' => 'X-1',
        'flag' => 'true',
        'meta' => '{"size":3}',
        'minutes' => 12,
        'off' => 'false',
        'price' => '12.5',
        'tags' => '{"a \\"b\\"","c\\\\d","e,f"}',
        'title' => 'Grøn "tekst"',
    ]);
});

it('gives the payload in the field-values input form, the extension fields under ext', function (): void {
    $fields = new FieldValues(
        storedMap(['title' => new TextValue('Grøn / tekst'), 'at' => new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00Z')), 'gone' => new NullValue, 'meta' => new GroupValue(new FieldMap)]),
        new ExtensionFields(new FieldNamespace('acme'), storedMap(['code' => new TextValue('X-1')])),
    );

    expect(StoredContent::payload($fields))->toBe('{"at":"2026-03-10T12:00:00.000000Z","gone":null,"meta":{},"title":"Grøn / tekst","ext":{"acme":{"code":"X-1"}}}')
        ->and(StoredContent::payload(new FieldValues))->toBe('{}')
        ->and(StoredContent::FORMAT_VERSION)->toBe(1);
});

it('refuses a value an encrypted column or a column of another kind cannot take', function (): void {
    expect(fn (): array => StoredContent::columns(storedType(storedField('secret', 'bytea', encrypted: true)), new FieldValues(storedMap(['secret' => new TextValue('x')]))))
        ->toThrow(LogicException::class, 'The encrypted field "secret" holds a value')
        ->and(StoredContent::columns(storedType(storedField('secret', 'bytea', encrypted: true)), new FieldValues(storedMap(['secret' => new NullValue]))))->toBe(['secret' => null])
        ->and(fn (): array => StoredContent::columns(storedType(storedField('tags', 'text[]')), new FieldValues(storedMap(['tags' => new TextValue('x')]))))
        ->toThrow(LogicException::class, 'The field "tags" holds a '.TextValue::class.', which its text[] column cannot take')
        ->and(fn (): array => StoredContent::columns(storedType(storedField('title', 'text')), new FieldValues(storedMap(['title' => new GroupValue(new FieldMap)]))))
        ->toThrow(LogicException::class, 'The field "title" holds a '.GroupValue::class.', which its text column cannot take')
        ->and(fn (): array => StoredContent::columns(storedType(storedField('tags', 'text[]')), new FieldValues(storedMap(['tags' => new ListValue(new GroupValue(new FieldMap))]))))
        ->toThrow(LogicException::class, 'which its text[] column cannot take');
});

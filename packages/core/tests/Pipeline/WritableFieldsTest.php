<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ClosedValue;
use Cbox\Cms\Core\Pipeline\Domain\WritableFields;
use Cbox\Cms\Core\Tests\Entries\BriefType;

/*
 * WritableFields, the rule that a writer sets only the fields it may read (PRD 2.31, 12.2): it
 * names each value for a closed field at its path, an extension field's below ext and its
 * namespace and a nested field's in each item of a repeated group, and keeps every closed field
 * from the current content, item by item in a repeated group and at every depth. The type is a
 * test type with a repeated public group `stops` whose items hold a `label` open to agents and a
 * group `gate` whose `pin` is closed to agents, and the extender `acme`'s confidential `ref`.
 */

function writableType(): TypeDefinition
{
    $text = static fn (string $handle, bool $agents): FieldDefinition => new FieldDefinition(null, new FieldHandle($handle), 'text', ClassificationAccess::Public, $agents, false, false, false, false, null);

    return new TypeDefinition(
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003d3'),
        new TypeName('test:route'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [new ExtensionVersion(new FieldNamespace('acme'), 1)],
        [
            new FieldDefinition(new FieldNamespace('acme'), new FieldHandle('ref'), 'text', ClassificationAccess::Confidential, false, false, false, false, false, new ColumnDefinition('ext__acme__ref', 'text', false, [])),
            new FieldDefinition(null, new FieldHandle('stops'), 'group', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('stops', 'jsonb', false, []), [
                new FieldDefinition(null, new FieldHandle('gate'), 'group', ClassificationAccess::Public, true, false, false, false, false, null, [$text('pin', false), $text('side', true)]),
                $text('label', true),
            ]),
        ],
    );
}

/**
 * @param  array<string, FieldValue>  $fields
 */
function writableGroup(array $fields): GroupValue
{
    $named = [];

    foreach ($fields as $handle => $value) {
        $named[] = new NamedValue(new FieldHandle($handle), $value);
    }

    return new GroupValue(new FieldMap(...$named));
}

function writableStop(string $label, ?string $pin = null, string $side = 'left'): GroupValue
{
    return writableGroup(['label' => new TextValue($label), 'gate' => writableGroup($pin === null ? ['side' => new TextValue($side)] : ['pin' => new TextValue($pin), 'side' => new TextValue($side)])]);
}

function writableFields(FieldValue $stops, ?string $ref = null): FieldValues
{
    return new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('stops'), $stops)),
        ...($ref === null ? [] : [new ExtensionFields(new FieldNamespace('acme'), new FieldMap(new NamedValue(new FieldHandle('ref'), new TextValue($ref))))]),
    );
}

/**
 * @param  list<ClosedValue>  $closed
 * @return list<string>
 */
function writablePaths(array $closed): array
{
    return array_map(static fn (ClosedValue $value): string => $value->path->toString(), $closed);
}

it('names each closed value at its path, nested fields in each item of a repeated group and an extension field below ext', function (): void {
    $fields = writableFields(new ListValue(writableStop('North', '1234'), writableStop('South'), writableStop('East', '9876')), 'R-1');

    expect(writablePaths(WritableFields::closed(writableType(), $fields, ClassificationAccess::Internal, true, new FieldPath('fields'))))
        ->toBe(['fields.stops[0].gate.pin', 'fields.stops[2].gate.pin', 'fields.ext.acme.ref'])
        ->and(writablePaths(WritableFields::closed(writableType(), $fields, ClassificationAccess::Internal, false, new FieldPath('fields'))))->toBe(['fields.ext.acme.ref'])
        ->and(WritableFields::closed(writableType(), $fields, ClassificationAccess::Confidential, false, new FieldPath('fields')))->toBe([]);
});

it('says whether a type hides a field or a nested field from the writer', function (): void {
    expect(WritableFields::hidesAny(writableType(), ClassificationAccess::Confidential, false))->toBeFalse()
        ->and(WritableFields::hidesAny(writableType(), ClassificationAccess::Internal, false))->toBeTrue()
        ->and(WritableFields::hidesAny(BriefType::definition(), ClassificationAccess::Internal, false))->toBeFalse()
        ->and(WritableFields::hidesAny(BriefType::definition(), ClassificationAccess::Internal, true))->toBeTrue()
        ->and(WritableFields::hidesAny(BriefType::definition(), ClassificationAccess::Public, false))->toBeTrue();
});

it('keeps each closed field from the current content, item by item and at every depth, and the written value of every other', function (): void {
    $current = writableFields(new ListValue(writableStop('North', '1234'), writableStop('South', '5555')), 'R-1');
    $written = writableFields(new ListValue(writableStop('North quay', side: 'right'), writableStop('South quay'), writableStop('New stop')));

    expect(WritableFields::kept(writableType(), $written, $current, ClassificationAccess::Internal, true)->equals(
        writableFields(new ListValue(writableStop('North quay', '1234', 'right'), writableStop('South quay', '5555'), writableStop('New stop')), 'R-1'),
    ))->toBeTrue()
        ->and(WritableFields::kept(writableType(), $written, $current, ClassificationAccess::Internal, false)->equals(
            writableFields(new ListValue(writableStop('North quay', side: 'right'), writableStop('South quay'), writableStop('New stop')), 'R-1'),
        ))->toBeTrue()
        ->and(WritableFields::kept(writableType(), $written, $current, ClassificationAccess::Confidential, false)->equals($written))->toBeTrue();
});

it('leaves a closed field out when the current content has none, and keeps a field the type does not declare for the validator', function (): void {
    $undeclared = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('green'))));

    expect(WritableFields::kept(writableType(), writableFields(new ListValue, 'R-9'), new FieldValues, ClassificationAccess::Internal, false)->equals(writableFields(new ListValue)))->toBeTrue()
        ->and(WritableFields::kept(writableType(), $undeclared, new FieldValues, ClassificationAccess::Internal, true)->equals($undeclared))->toBeTrue();
});

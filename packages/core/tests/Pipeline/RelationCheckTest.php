<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
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
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Pipeline\Domain\RelationCheck;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRelationTargets;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LinkedProbeType;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;

/*
 * The kernel's check of the relations a plan writes (PRD 5.11, 6.2 phase 5), with test:linked:
 * every target is an entry that exists, or one the plan creates, of a type its field allows; a
 * target the validators refused at its path already is not checked again; and the targets of the
 * whole plan are read at once, or not at all when there is none to read.
 */

const LINKED_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000003e1';

const LINKED_OTHER = '01936f5e-8a2b-7c3d-9e4f-0000000003e2';

const LINKED_PROBE = '01936f5e-8a2b-7c3d-9e4f-0000000003e3';

const LINKED_MISSING = '01936f5e-8a2b-7c3d-9e4f-0000000003e9';

const LINKED_NEW = '01936f5e-8a2b-7c3d-9e4f-0000000003e4';

const LINKED_HOME = '01936f5e-8a2b-7c3d-9e4f-0000000003a1';

/**
 * @param  array<string, FieldValue>  $fields
 */
function linkedRevision(array $fields, ?ExtensionFields $extension = null, string $entry = LINKED_ENTRY): RevisionCreated
{
    $named = [];

    foreach ($fields as $handle => $value) {
        $named[] = new NamedValue(new FieldHandle($handle), $value);
    }

    $values = $extension instanceof ExtensionFields ? new FieldValues(new FieldMap(...$named), $extension) : new FieldValues(new FieldMap(...$named));

    return new RevisionCreated(EntryId::fromString($entry), TypeId::fromString(LinkedProbeType::ID), VariantKey::shared(), new RevisionNumber(1), $values);
}

/**
 * @param  list<string>  $ids
 */
function linkedList(array $ids): ListValue
{
    return new ListValue(...array_map(static fn (string $id): TextValue => new TextValue($id), $ids));
}

/**
 * @param  list<CatalogError>  $refused
 * @return array{list<string>, int}
 */
function linkedCheck(Plan $plan, array $refused = []): array
{
    $targets = new FakeRelationTargets()
        ->with(EntryId::fromString(LINKED_OTHER), TypeId::fromString(LinkedProbeType::ID))
        ->with(EntryId::fromString(LINKED_PROBE), TypeId::fromString(ProbeType::ID));
    $check = new RelationCheck(
        new FakeFieldValidation(new FakeTypeValidators(new LinkedProbeType, new ProbeType)),
        $targets,
        new FakeTypeCatalog(LinkedProbeType::definition(), ProbeType::definition()),
    );
    $errors = $check->errors($plan, new FieldPath('fields'), $refused);

    return [array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $errors), $targets->reads];
}

it('passes targets that exist with a type their field allows, read in one call for the whole plan', function (): void {
    $plan = new Plan(
        linkedRevision(['next' => new TextValue(LINKED_OTHER), 'cited' => linkedList([LINKED_OTHER, LINKED_PROBE])]),
        linkedRevision(['meta' => new GroupValue(new FieldMap(new NamedValue(new FieldHandle('source'), new TextValue(LINKED_PROBE))))], entry: LINKED_OTHER),
    );

    expect(linkedCheck($plan))->toBe([[], 1]);
});

it('refuses a missing target and one of a type the field does not allow, at the field\'s path, in the owner\'s fields, a group and an extension', function (): void {
    $extension = new ExtensionFields(new FieldNamespace(ProbeType::EXTENDER), new FieldMap(new NamedValue(new FieldHandle('see'), new TextValue(LINKED_PROBE))));
    $plan = new Plan(linkedRevision([
        'next' => new TextValue(LINKED_MISSING),
        'cited' => linkedList([LINKED_PROBE, LINKED_MISSING]),
        'meta' => new GroupValue(new FieldMap(new NamedValue(new FieldHandle('source'), new TextValue(LINKED_OTHER)))),
    ], $extension));

    expect(linkedCheck($plan))->toBe([[
        'validation_relation_target_missing fields.next',
        'validation_relation_target_missing fields.cited',
        'validation_relation_target_type fields.meta.source',
        'validation_relation_target_type fields.ext.probe.see',
    ], 1]);
});

it('takes an entry the plan creates as a target with its type, and reads nothing when every target is one', function (): void {
    $plan = new Plan(
        new EntryCreated(EntryId::fromString(LINKED_NEW), TypeId::fromString(LinkedProbeType::ID), NodeId::fromString(LINKED_HOME)),
        linkedRevision(['next' => new TextValue(LINKED_NEW), 'cited' => linkedList([LINKED_NEW])], entry: LINKED_NEW),
    );
    $wrongType = new Plan(
        new EntryCreated(EntryId::fromString(LINKED_NEW), TypeId::fromString(LinkedProbeType::ID), NodeId::fromString(LINKED_HOME)),
        linkedRevision(['meta' => new GroupValue(new FieldMap(new NamedValue(new FieldHandle('source'), new TextValue(LINKED_NEW))))], entry: LINKED_NEW),
    );

    expect(linkedCheck($plan))->toBe([[], 0])
        ->and(linkedCheck($wrongType))->toBe([['validation_relation_target_type fields.meta.source'], 0]);
});

it('reads nothing for a plan without relations, and leaves a value the validators refused at its path', function (): void {
    $refused = [new CatalogError(ErrorCode::ValidationDuplicateItem, new FieldPath('fields', 'next'), 'is refused.')];

    expect(linkedCheck(new Plan(linkedRevision([]))))->toBe([[], 0])
        ->and(linkedCheck(new Plan(linkedRevision(['next' => new TextValue('not-an-id')]))))->toBe([[], 0])
        ->and(linkedCheck(new Plan(linkedRevision(['next' => new TextValue(LINKED_MISSING)])), $refused))->toBe([[], 0]);
});

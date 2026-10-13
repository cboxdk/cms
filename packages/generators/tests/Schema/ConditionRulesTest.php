<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Validation\ConditionOperator;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ConditionReference;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldCondition;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;

/*
 * The rules of conditional fields (gap G3, B2-T73): a field's when names a field of its own set
 * that it can reach, of its classification or lower, held by its variant and compared with values
 * of that field's kind, and the conditions never read each other in a circle. Each refusal has its
 * own generate_when_* code, exit 65, at the comparison that breaks it; a file that keeps the rules
 * is read into the model, and the descriptor counts each reference in groups up.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * A type file of the owner with the fields in YAML, each a list item.
 */
function conditionType(string $fields, string $localization = 'none', string $handle = 'thing'): string
{
    return <<<YAML
        blueprint: 1
        kind: type
        type_id: 0192a3b4-c5d6-7e8f-9a0b-000000000031
        handle: {$handle}
        label: Thing
        description: A type for the rules of conditions.
        version: 1
        capabilities:
          history: full
          stages: none
          localization: {$localization}
        fields:
        {$fields}
        YAML;
}

/**
 * An extension file of acme for the type of conditionType() with the fields in YAML.
 */
function conditionExtension(string $fields): string
{
    return <<<YAML
        blueprint: 1
        kind: extension
        extends: 0192a3b4-c5d6-7e8f-9a0b-000000000031
        version: 1
        fields:
        {$fields}
        YAML;
}

/** The fields every case starts from: a select, a text, an internal text and a repeated group. */
const CONDITION_FIELDS = <<<'YAML'
  - handle: kind
    label: Kind
    description: The kind of the link.
    type: select
    classification: public
    options:
      - value: internal_link
        label: Internal
      - value: external_link
        label: External
  - handle: url
    label: URL
    description: Where an external link goes.
    type: text
    required: true
    classification: public
    when:
      field: kind
      eq: external_link
    when_hidden: clear
  - handle: secret
    label: Secret
    description: A note for the editors.
    type: text
    classification: internal
  - handle: count
    label: Count
    description: How many there are.
    type: integer
    classification: public
  - handle: parts
    label: Parts
    description: The parts.
    type: group
    classification: public
    repeat:
      max_items: 5
    fields:
      - handle: part_kind
        label: Part kind
        description: The kind of the part.
        type: text
      - handle: part_note
        label: Part note
        description: A note on a part of an external link.
        type: text
        when:
          all:
            - field: part_kind
              not_null: true
            - field: ../kind
              in: [external_link]
        when_hidden: keep

YAML;

/**
 * A field of the type with its keys, each a line such as "type: text", at the indent of a list item.
 */
function conditionField(string $handle, string ...$keys): string
{
    return implode("\n", [
        '  - handle: '.$handle,
        '    label: Field '.$handle,
        '    description: A field for the rules of conditions.',
        ...array_map(static fn (string $key): string => '    '.$key, $keys),
    ])."\n";
}

/**
 * The roots with the files: the app's, and acme's when it has files.
 *
 * @param  array<string, string>  $files  contents by path below the app root
 * @param  array<string, string>  $extension  contents by path below the acme root
 * @return list<SchemaRoot>
 */
function conditionRoots(array $files, array $extension = []): array
{
    $base = SchemaFixtures::scratch();
    $roots = [];

    foreach (['app' => $files, 'acme' => $extension] as $owner => $contents) {
        if ($contents === []) {
            continue;
        }

        $root = new SchemaRoot(new Owner($owner), $base, $owner);
        mkdir($root->path(), 0o775, true);

        foreach ($contents as $path => $text) {
            SchemaFixtures::write($root->path().'/'.$path, $text);
        }

        $roots[] = $root;
    }

    return $roots;
}

/**
 * The problems of reading the files, which must fail: each as its code and the place it names.
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $extension
 * @return list<string> each problem as `<code> <file>, <pointer>`
 */
function conditionProblems(array $files, array $extension = []): array
{
    try {
        ComprehensiveExample::source()->read(conditionRoots($files, $extension));
    } catch (GenerationFailed $failed) {
        return array_map(static fn (GenerationProblem $problem): string => $problem->code->value.' '.strstr($problem->message, ': ', true), $failed->problems);
    }

    throw new LogicException('The blueprints were read.');
}

/**
 * The message of the only problem of reading the files.
 *
 * @param  array<string, string>  $files
 */
function conditionMessage(array $files): string
{
    try {
        ComprehensiveExample::source()->read(conditionRoots($files));
    } catch (GenerationFailed $failed) {
        expect($failed->problems)->toHaveCount(1);

        return $failed->problems[0]->message;
    }

    throw new LogicException('The blueprints were read.');
}

/**
 * The blueprints of the files, which must be read.
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $extension
 */
function conditionBlueprints(array $files, array $extension = []): Blueprints
{
    return ComprehensiveExample::source()->read(conditionRoots($files, $extension));
}

/**
 * The descriptor field at the path of handles, below the type's top-level fields.
 *
 * @param  list<FieldDescriptor>  $fields
 */
function conditionDescriptor(array $fields, string ...$path): FieldDescriptor
{
    $handle = array_shift($path);

    foreach ($fields as $field) {
        if (($field->column->name ?? $field->handle->value) === $handle) {
            return $path === [] ? $field : conditionDescriptor($field->fields, ...$path);
        }
    }

    throw new LogicException(sprintf('No field %s.', (string) $handle));
}

it('reads a when and its when_hidden into the model, a nested field\'s too', function (): void {
    $type = conditionBlueprints(['thing.yaml' => conditionType(CONDITION_FIELDS)])->types[0];
    $url = $type->fields[1];
    $note = $type->fields[4]->options->nestedFields()[1];

    expect($url->when)->toBeInstanceOf(FieldCondition::class)
        ->and($url->when?->operator)->toBe(ConditionOperator::Eq)
        ->and($url->when?->field)->toEqual(new ConditionReference(0, null, new Handle('kind')))
        ->and($url->when?->values)->toBe(['external_link'])
        ->and($url->whenHidden)->toBe(WhenHidden::Clear)
        ->and($note->when?->operator)->toBe(ConditionOperator::All)
        ->and(array_map(static fn (FieldCondition $condition): string => $condition->field?->written() ?? '', $note->when->conditions ?? []))->toBe(['part_kind', '../kind'])
        ->and($note->when?->conditions[1]->values ?? null)->toBe(['external_link'])
        ->and($note->whenHidden)->toBe(WhenHidden::Keep)
        ->and($type->fields[0]->when)->toBeNull()
        ->and($type->fields[0]->whenHidden)->toBeNull();
});

it('compiles a conditional field nullable, keeps its presence, and counts each reference in groups up', function (): void {
    $extension = conditionExtension(conditionField('tag', 'type: text', 'classification: public', 'required: true', 'when: {field: ext.acme.flag, eq: true}', 'when_hidden: clear')
        .conditionField('flag', 'type: boolean', 'classification: public')
        .conditionField('extras', 'type: group', 'classification: public', 'fields:', '  - handle: extra', '    label: Extra', '    description: An extra.', '    type: text', '    when: {field: ext.acme.flag, not_null: true}', '    when_hidden: keep'));
    $schema = DescriptorCompiler::compile(SchemaResolver::resolve(conditionBlueprints(['thing.yaml' => conditionType(CONDITION_FIELDS)], ['thing.yaml' => $extension])));
    $fields = $schema->types[0]->fields;
    $url = conditionDescriptor($fields, 'url');
    $note = conditionDescriptor($fields, 'parts', 'part_note');
    $tag = conditionDescriptor($fields, 'ext__acme__tag');
    $extra = conditionDescriptor($fields, 'ext__acme__extras', 'extra');

    expect($url->column?->notNull)->toBeFalse()
        ->and($url->php->nullable)->toBeTrue()
        ->and($url->validation[0]->name->value)->toBe('nullable')
        ->and($url->presence())->toBe(Presence::Required)
        ->and($url->when?->field)->toEqual(FieldReference::sibling('kind'))
        ->and($url->whenHidden)->toBe(WhenHidden::Clear)
        ->and(array_map(static fn (FieldReference $reference): string => $reference->toString(), $note->when?->references() ?? []))->toBe(['part_kind', '../kind'])
        ->and($tag->presence())->toBe(Presence::RequiredOnRelease)
        ->and($tag->when?->field?->toString())->toBe('flag')
        ->and($extra->when?->field?->toString())->toBe('../flag');
});

it('refuses a reference to a field it cannot reach with generate_when_unknown_field: no such handle, too many groups up, a field inside a group', function (): void {
    $fields = CONDITION_FIELDS
        .conditionField('a', 'type: text', 'classification: public', 'when: {field: missing, eq: x}', 'when_hidden: clear')
        .conditionField('b', 'type: text', 'classification: public', 'when: {field: ../kind, eq: x}', 'when_hidden: clear')
        .conditionField('c', 'type: text', 'classification: public', 'when: {field: part_kind, "null": true}', 'when_hidden: keep');

    expect(conditionProblems(['thing.yaml' => conditionType($fields)]))->toBe([
        'generate_when_unknown_field app/thing.yaml, /fields/5/when',
        'generate_when_unknown_field app/thing.yaml, /fields/6/when',
        'generate_when_unknown_field app/thing.yaml, /fields/7/when',
    ])->and(conditionMessage(['thing.yaml' => conditionType(CONDITION_FIELDS.conditionField('a', 'type: text', 'classification: public', 'when: {field: missing, eq: x}', 'when_hidden: clear'))]))
        ->toBe('app/thing.yaml, /fields/5/when: the condition of a reads missing, and no field there has the handle missing. Name a sibling, or with ../ a sibling of a group the field is in.');
});

it('refuses conditions that read each other in a circle, a field that reads itself and one that reads its group with generate_when_cycle', function (): void {
    $circle = conditionField('a', 'type: boolean', 'classification: public', 'when: {field: b, not_null: true}', 'when_hidden: keep')
        .conditionField('b', 'type: boolean', 'classification: public', 'when: {any: [{field: count, eq: 1}, {field: a, eq: true}]}', 'when_hidden: keep');
    $self = conditionField('d', 'type: text', 'classification: public', 'when: {field: d, not_null: true}', 'when_hidden: keep');
    $group = str_replace("            - field: ../kind\n              in: [external_link]\n", "            - field: ../parts\n              not_null: true\n", CONDITION_FIELDS);

    expect(conditionProblems(['thing.yaml' => conditionType(CONDITION_FIELDS.$circle)]))->toBe(['generate_when_cycle app/thing.yaml, /fields/6/when/any/1'])
        ->and(conditionMessage(['thing.yaml' => conditionType(CONDITION_FIELDS.$circle)]))->toBe('app/thing.yaml, /fields/6/when/any/1: the conditions read each other in a circle: a -> b -> a. Remove one of the references.')
        ->and(conditionProblems(['thing.yaml' => conditionType(CONDITION_FIELDS.$self)]))->toBe(['generate_when_cycle app/thing.yaml, /fields/5/when'])
        ->and(conditionProblems(['thing.yaml' => conditionType($group)]))->toBe(['generate_when_cycle app/thing.yaml, /fields/4/fields/1/when/all/1']);
});

it('refuses a reference to another owner\'s namespace with generate_when_foreign_namespace, and reads the extender\'s own', function (): void {
    $owner = CONDITION_FIELDS.conditionField('a', 'type: text', 'classification: public', 'when: {field: ext.acme.flag, eq: true}', 'when_hidden: clear');
    $extension = conditionExtension(conditionField('flag', 'type: boolean', 'classification: public')
        .conditionField('own', 'type: text', 'classification: public', 'when: {field: ext.acme.flag, eq: true}', 'when_hidden: clear')
        .conditionField('other', 'type: text', 'classification: public', 'when: {field: ext.shop.flag, eq: true}', 'when_hidden: clear'));

    expect(conditionProblems(['thing.yaml' => conditionType($owner)], ['thing.yaml' => $extension]))->toBe([
        'generate_when_foreign_namespace acme/thing.yaml, /fields/2/when',
        'generate_when_foreign_namespace app/thing.yaml, /fields/5/when',
    ]);
});

it('refuses a reference to a field classified above the conditional field with generate_when_classification_above, and allows one at or below it', function (): void {
    $above = conditionField('a', 'type: text', 'classification: public', 'when: {field: secret, not_null: true}', 'when_hidden: clear');
    $below = conditionField('b', 'type: text', 'classification: internal', 'when: {field: kind, eq: internal_link}', 'when_hidden: clear');

    expect(conditionProblems(['thing.yaml' => conditionType(CONDITION_FIELDS.$above.$below)]))->toBe(['generate_when_classification_above app/thing.yaml, /fields/5/when'])
        ->and(conditionMessage(['thing.yaml' => conditionType(CONDITION_FIELDS.$above)]))->toBe('app/thing.yaml, /fields/5/when: the condition reads secret, classified internal, above the classification public of the field it shows, so a reader who may see the field could not tell whether it is shown. Read a field classified public or lower.');
});

it('refuses a reference to a field another variant holds with generate_when_other_variant', function (): void {
    $fields = conditionField('title', 'type: text', 'classification: public', 'translatable: true')
        .conditionField('code', 'type: text', 'classification: public')
        .conditionField('teaser', 'type: text', 'classification: public', 'translatable: true', 'when: {field: code, not_null: true}', 'when_hidden: clear')
        .conditionField('summary', 'type: text', 'classification: public', 'translatable: true', 'when: {field: title, not_null: true}', 'when_hidden: clear');

    expect(conditionProblems(['thing.yaml' => conditionType($fields, 'variants')]))->toBe(['generate_when_other_variant app/thing.yaml, /fields/2/when']);
});

it('refuses a comparison that does not fit the field it reads with generate_when_value_wrong_kind', function (string $keys): void {
    $fields = CONDITION_FIELDS
        .conditionField('tags', 'type: select', 'classification: public', 'multiple: true', 'options:', '  - value: one', '    label: One')
        .conditionField('a', 'type: text', 'classification: public', 'when: '.$keys, 'when_hidden: clear');

    expect(conditionProblems(['thing.yaml' => conditionType($fields)]))->toBe(['generate_when_value_wrong_kind app/thing.yaml, /fields/6/when']);
})->with([
    'a string for an integer' => ['{field: count, eq: "1"}'],
    'a whole number for a text' => ['{field: url, neq: 1}'],
    'a value that is not an option' => ['{field: kind, in: [internal_link, mail_link]}'],
    'a group compared' => ['{field: parts, eq: x}'],
    'a select with multiple compared' => ['{field: tags, eq: one}'],
]);

it('allows null and not_null on any field, and eq on a boolean, a text and an integer', function (): void {
    $fields = CONDITION_FIELDS
        .conditionField('flag', 'type: boolean', 'classification: public')
        .conditionField('a', 'type: text', 'classification: public', 'when: {any: [{field: parts, not_null: true}, {field: flag, eq: false}, {field: count, in: [1, 2]}, {field: url, neq: x}, {field: kind, "null": true}]}', 'when_hidden: keep');

    expect(conditionBlueprints(['thing.yaml' => conditionType($fields)])->types[0]->fields[6]->when?->conditions)->toHaveCount(5);
});

it('refuses a when without when_hidden, and a when_hidden without a when, by the blueprint schema', function (): void {
    expect(conditionProblems(['thing.yaml' => conditionType(CONDITION_FIELDS.conditionField('a', 'type: text', 'classification: public', 'when: {field: kind, eq: internal_link}'))]))
        ->toBe(['generate_schema_invalid app/thing.yaml, /fields/5'])
        ->and(conditionProblems(['thing.yaml' => conditionType(CONDITION_FIELDS.conditionField('a', 'type: text', 'classification: public', 'when_hidden: keep'))]))
        ->toBe(['generate_schema_invalid app/thing.yaml, /fields/5']);
});

it('exits with 65 for each of the codes, as a refused blueprint does', function (): void {
    foreach ([GenerateErrorCode::WhenUnknownField, GenerateErrorCode::WhenCycle, GenerateErrorCode::WhenForeignNamespace, GenerateErrorCode::WhenClassificationAbove, GenerateErrorCode::WhenOtherVariant, GenerateErrorCode::WhenValueWrongKind] as $code) {
        expect(ErrorCode::from($code->value)->entry()->exit)->toBe(ExitCode::DataErr, $code->value);
    }
});

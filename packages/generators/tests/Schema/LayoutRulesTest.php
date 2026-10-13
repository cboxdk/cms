<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\DefaultToken;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldUi;
use Cbox\Cms\Generators\Schema\Domain\Dto\Layout;
use Cbox\Cms\Generators\Schema\Domain\Dto\LocalizedText;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\LayoutWidth;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SelectDisplay;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;

/*
 * The rules of the presentation keys (gap G2, B2-T72): a layout places every top-level field of
 * its file exactly once with a width preset, a default is of the kind the field type takes, and a
 * summary names the group's fields. Each refusal has its own generate_* code, exit 65, at the file
 * and JSON pointer that break it; a file that keeps the rules is read into the model.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const LAYOUT_TYPE_ID = '0192a3b4-c5d6-7e8f-9a0b-000000000011';

/**
 * A root of the owner below the base, with the given files.
 *
 * @param  array<string, string>  $files  contents by path below the root
 */
function layoutRoot(string $base, array $files, string $owner = 'app', string $directory = 'schema'): SchemaRoot
{
    $root = new SchemaRoot(new Owner($owner), $base, $directory);
    mkdir($root->path(), 0o775, true);

    foreach ($files as $path => $contents) {
        SchemaFixtures::write($root->path().'/'.$path, $contents);
    }

    return $root;
}

/**
 * A top-level field of the type with its options, each option a line such as "min: 1".
 */
function layoutField(string $handle, string $type, string ...$options): string
{
    $lines = [
        '  - handle: '.$handle,
        '    label: Layout field',
        '    description: A field for the rules of the layout.',
        '    type: '.$type,
        '    classification: internal',
        ...array_map(static fn (string $option): string => '    '.$option, $options),
    ];

    return implode("\n", $lines)."\n";
}

/**
 * A type file with the given fields and layout lines.
 */
function layoutType(string $fields, string $layout, string $label = 'label: Thing'): string
{
    return <<<YAML
        blueprint: 1
        kind: type
        type_id: 0192a3b4-c5d6-7e8f-9a0b-000000000011
        handle: thing
        {$label}
        description: A type for the rules of the layout.
        version: 1
        capabilities:
          history: full
          stages: none
          localization: none
        fields:
        {$fields}
        {$layout}
        YAML;
}

const LAYOUT_FIELDS = <<<'YAML'
  - handle: title
    label: Title
    description: The title.
    type: text
    classification: public
  - handle: count
    label: Count
    description: The count.
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
      - handle: part_name
        label: Part name
        description: The name of the part.
        type: text
YAML;

const LAYOUT_ALL = <<<'YAML'
layout:
  tabs:
    - handle: main
      label: Main
      sections:
        - handle: top
          fields:
            - field: title
              width: half
            - field: count
              width: half
  sidebar:
    sections:
      - handle: side
        fields:
          - field: parts
YAML;

/**
 * The problems of reading the files of the roots, which must fail.
 *
 * @param  array<string, string>  $files  contents by path below the app root
 * @param  array<string, string>  $extension  contents by path below the acme root
 * @return list<string> each problem as `<code> <message>`
 */
function layoutProblems(array $files, array $extension = []): array
{
    $base = SchemaFixtures::scratch();
    $roots = [layoutRoot($base, $files)];

    if ($extension !== []) {
        $roots[] = layoutRoot($base, $extension, 'acme', 'acme');
    }

    try {
        ComprehensiveExample::source()->read($roots);
    } catch (GenerationFailed $failed) {
        return array_map(static fn (GenerationProblem $problem): string => $problem->code->value.' '.$problem->message, $failed->problems);
    }

    throw new LogicException('The blueprints were read.');
}

/**
 * The blueprints of the files, which must be read.
 *
 * @param  array<string, string>  $files  contents by path below the app root
 */
function layoutBlueprints(array $files): Blueprints
{
    return ComprehensiveExample::source()->read([layoutRoot(SchemaFixtures::scratch(), $files)]);
}

it('refuses a layout that leaves out a top-level field with generate_layout_field_omitted, at the layout', function (): void {
    $layout = str_replace("            - field: count\n              width: half\n", '', LAYOUT_ALL);

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, $layout)]))->toBe([
        'generate_layout_field_omitted schema/thing.yaml, /layout: the layout leaves out the field count at schema/thing.yaml, /fields/1. A layout places every top-level field of the type thing exactly once: add it to a section, or remove the layout.',
    ]);
});

it('refuses a layout that places a field twice with generate_layout_field_repeated, naming the first placement', function (): void {
    $layout = LAYOUT_ALL."\n          - field: title";

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, $layout)]))->toBe([
        'generate_layout_field_repeated schema/thing.yaml, /layout/sidebar/sections/0/fields/1/field: the field title is already placed at schema/thing.yaml, /layout/tabs/0/sections/0/fields/0. A layout places every field exactly once.',
    ]);
});

it('refuses a placement of a field the file has no top-level field for with generate_layout_field_unknown: a nested field, a missing handle', function (): void {
    $layout = LAYOUT_ALL."\n          - field: part_name\n          - field: missing";

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, $layout)]))->toBe([
        'generate_layout_field_unknown schema/thing.yaml, /layout/sidebar/sections/0/fields/1/field: the type thing has no top-level field part_name. A layout places the top-level fields of its own file, never a field inside a group or another owner\'s field.',
        'generate_layout_field_unknown schema/thing.yaml, /layout/sidebar/sections/0/fields/2/field: the type thing has no top-level field missing. A layout places the top-level fields of its own file, never a field inside a group or another owner\'s field.',
    ]);
});

it('refuses a width that is not a preset with generate_layout_width_unknown, naming the presets', function (): void {
    $layout = str_replace('width: half', 'width: wide', LAYOUT_ALL);

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, $layout)]))->toBe([
        'generate_layout_width_unknown schema/thing.yaml, /layout/tabs/0/sections/0/fields/0/width: the width wide is not a preset. The presets are full, half, third, two_thirds.',
        'generate_layout_width_unknown schema/thing.yaml, /layout/tabs/0/sections/0/fields/1/width: the width wide is not a preset. The presets are full, half, third, two_thirds.',
    ]);
});

it('refuses two tabs, or two sections of one tab or of the sidebar, with one handle with generate_layout_duplicate_handle', function (): void {
    $layout = <<<'YAML'
        layout:
          tabs:
            - handle: main
              label: Main
              sections:
                - handle: top
                  fields:
                    - field: title
                - handle: top
                  fields:
                    - field: count
            - handle: main
              label: Again
              sections:
                - handle: other
                  fields:
                    - field: parts
        YAML;

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, $layout)]))->toBe([
        'generate_layout_duplicate_handle schema/thing.yaml, /layout/tabs/0/sections/1/handle: the section top is already the section at schema/thing.yaml, /layout/tabs/0/sections/0. The sections of the tab main need different handles.',
        'generate_layout_duplicate_handle schema/thing.yaml, /layout/tabs/1/handle: the tab main is already the tab at schema/thing.yaml, /layout/tabs/0. The tabs of a layout need different handles.',
    ]);
});

it('holds an extension\'s fragment to the extension\'s own fields: an omitted field, and the owner\'s field as unknown', function (): void {
    $extension = <<<'YAML'
        blueprint: 1
        kind: extension
        extends: 0192a3b4-c5d6-7e8f-9a0b-000000000011
        version: 1
        fields:
          - handle: tag
            label: Tag
            description: A tag.
            type: text
            classification: public
          - handle: note
            label: Note
            description: A note.
            type: text
            classification: public
        layout:
          tabs:
            - handle: main
              label: Main
              sections:
                - handle: top
                  fields:
                    - field: tag
                    - field: title
        YAML;

    expect(layoutProblems(['thing.yaml' => layoutType(LAYOUT_FIELDS, LAYOUT_ALL)], ['thing.yaml' => $extension]))->toBe([
        'generate_layout_field_omitted acme/thing.yaml, /layout: the layout leaves out the field note at acme/thing.yaml, /fields/1. A layout places every top-level field of the fields acme adds to the type 0192a3b4-c5d6-7e8f-9a0b-000000000011 exactly once: add it to a section, or remove the layout.',
        'generate_layout_field_unknown acme/thing.yaml, /layout/tabs/0/sections/0/fields/1/field: the fields acme adds to the type 0192a3b4-c5d6-7e8f-9a0b-000000000011 has no top-level field title. A layout places the top-level fields of its own file, never a field inside a group or another owner\'s field.',
    ]);
});

it('refuses a default of the wrong kind with generate_default_wrong_kind, for each core field type', function (string $field, string $takes): void {
    $fields = LAYOUT_FIELDS."\n".$field;

    expect(layoutProblems(['thing.yaml' => layoutType($fields, '')]))->toBe([
        'generate_default_wrong_kind schema/thing.yaml, /fields/3/default: '.$takes.' Correct the default, or remove it.',
    ]);
})->with([
    'an integer on a text' => [layoutField('name', 'text', 'default: 7'), 'the default is an integer, and a text field takes a string.'],
    'a string on an integer' => [layoutField('size', 'integer', "default: 'seven'"), 'the default is a string, and a integer field takes an integer.'],
    'a number of another form on a decimal' => [layoutField('price', 'decimal', 'precision: 5', 'scale: 2', "default: 'free'"), 'the default is a string, and a decimal field takes a decimal as a quoted string, such as "0.00".'],
    'a string on a boolean' => [layoutField('flag', 'boolean', "default: 'yes'"), 'the default is a string, and a boolean field takes true or false.'],
    'the token now on a date' => [layoutField('day', 'date', 'default: now'), 'the default is a string, and a date field takes a quoted date, such as "2026-01-01", or the token today.'],
    'the token today on a datetime' => [layoutField('at', 'datetime', 'default: today'), 'the default is a string, and a datetime field takes a quoted time with its offset, such as "2026-01-01T00:00:00Z", or the token now.'],
    'a value that is not an option' => [layoutField('kind', 'select', 'options:', '  - value: one', '    label: One', 'default: two'), 'the default is a string, and a select field takes one of its options: one.'],
    'a list on a select that is not multiple' => [layoutField('kind', 'select', 'options:', '  - value: one', '    label: One', 'default: [one]'), 'the default is a list of strings, and a select field takes one of its options: one.'],
    'a list with a value twice on a multiple select' => [layoutField('kinds', 'select', 'multiple: true', 'options:', '  - value: one', '    label: One', 'default: [one, one]'), 'the default is a list of strings, and a select field takes a list of its options, each once: one.'],
    'a default on rich text' => [layoutField('body', 'rich_text', "default: 'text'"), 'the default is a string, and a rich_text field takes no default.'],
    'a default on a group' => [layoutField('box', 'group', "default: 'x'", 'fields:', '  - handle: inner', '    label: Inner', '    description: The inner field.', '    type: text'), 'the default is a string, and a group field takes no default.'],
]);

it('refuses a summary that names a field the group does not have with generate_summary_field_unknown', function (): void {
    $fields = str_replace("    repeat:\n      max_items: 5\n", "    repeat:\n      max_items: 5\n    ui:\n      summary: '{part_name} ({weight})'\n", LAYOUT_FIELDS);

    expect(layoutProblems(['thing.yaml' => layoutType($fields, '')]))->toBe([
        'generate_summary_field_unknown schema/thing.yaml, /fields/2/ui/summary: the summary names {weight}, and the group parts has no field weight. Name one of its fields: part_name.',
    ]);
});

it('exits with 65 for each of the codes, as a refused blueprint does', function (): void {
    foreach ([GenerateErrorCode::LayoutFieldOmitted, GenerateErrorCode::LayoutFieldRepeated, GenerateErrorCode::LayoutFieldUnknown, GenerateErrorCode::LayoutWidthUnknown, GenerateErrorCode::LayoutDuplicateHandle, GenerateErrorCode::DefaultWrongKind, GenerateErrorCode::SummaryFieldUnknown] as $code) {
        expect(ErrorCode::from($code->value)->entry()->exit)->toBe(ExitCode::DataErr, $code->value);
    }
});

it('reads the layout, the hints, the defaults and the labels per locale into the model, and the tokens today and now on a date and a datetime', function (): void {
    $fields = LAYOUT_FIELDS."\n".implode('', [
        layoutField('day', 'date', 'default: today'),
        layoutField('at', 'datetime', 'default: now'),
        layoutField('kinds', 'select', 'multiple: true', 'options:', '  - value: one', '    label: {en: One, da: En}', 'default: [one]', 'ui:', '  display: buttons', '  read_only: true'),
    ]);
    $fields = str_replace("    repeat:\n      max_items: 5\n", "    repeat:\n      max_items: 5\n    ui:\n      summary: '{part_name}'\n      instructions: {en: Add a part., da: Tilføj en del.}\n", $fields);
    $fields = str_replace("  - handle: title\n    label: Title\n", "  - handle: title\n    label: {en: Title, da: Titel}\n    default: Untitled\n    ui:\n      input_hint: The title\n      hidden: true\n", $fields);
    $layout = str_replace("      label: Main\n", "      label: {en: Main, da: Hoved}\n", LAYOUT_ALL)."\n          - field: day\n          - field: at\n          - field: kinds";

    $type = layoutBlueprints(['thing.yaml' => layoutType($fields, $layout, 'label: {en: Thing, da: Ting}')])->types[0];
    $byHandle = [];

    foreach ($type->fields as $field) {
        $byHandle[$field->handle->value] = $field;
    }

    expect($type)->toBeInstanceOf(TypeBlueprint::class)
        ->and($type->label)->toBe('Thing')
        ->and($type->labels)->toBe(['en' => 'Thing', 'da' => 'Ting'])
        ->and($type->layout)->toBeInstanceOf(Layout::class)
        ->and($type->layout?->tabs[0]->label->byLocale)->toBe(['en' => 'Main', 'da' => 'Hoved'])
        ->and($type->layout?->tabs[0]->sections[0]->fields[0]->width())->toBe(LayoutWidth::Half)
        ->and($type->layout?->sidebar[0]->fields[0]->width())->toBe(LayoutWidth::Full)
        ->and($byHandle['title']->labels)->toBe(['en' => 'Title', 'da' => 'Titel'])
        ->and($byHandle['title']->default)->toEqual(FieldDefault::constant('Untitled'))
        ->and($byHandle['title']->ui)->toEqual(new FieldUi(null, LocalizedText::plain('The title'), false, true, null, null))
        ->and($byHandle['day']->default)->toEqual(FieldDefault::token(DefaultToken::Today))
        ->and($byHandle['at']->default)->toEqual(FieldDefault::token(DefaultToken::Now))
        ->and($byHandle['kinds']->default)->toEqual(FieldDefault::values(['one']))
        ->and($byHandle['kinds']->ui?->display)->toBe(SelectDisplay::Buttons)
        ->and($byHandle['kinds']->ui?->readOnly)->toBeTrue()
        ->and($byHandle['parts']->ui?->summary)->toBe('{part_name}')
        ->and($byHandle['parts']->ui?->instructions?->byLocale)->toBe(['en' => 'Add a part.', 'da' => 'Tilføj en del.'])
        ->and($byHandle['count']->ui)->toBeNull()
        ->and($byHandle['count']->default)->toBeNull();
});

it('reads an extension file with a layout fragment, and one without', function (): void {
    $base = SchemaFixtures::scratch();
    $with = <<<'YAML'
        blueprint: 1
        kind: extension
        extends: 0192a3b4-c5d6-7e8f-9a0b-000000000011
        version: 1
        fields:
          - handle: tag
            label: Tag
            description: A tag.
            type: text
            classification: public
        layout:
          sidebar:
            sections:
              - handle: tags
                fields:
                  - field: tag
        YAML;
    $without = str_replace('tag', 'note', substr($with, 0, (int) strpos($with, 'layout:')));
    $roots = [layoutRoot($base, ['thing.yaml' => layoutType(LAYOUT_FIELDS, LAYOUT_ALL)]), layoutRoot($base, ['a.yaml' => $with, 'b.yaml' => $without], 'acme', 'acme')];

    $extensions = ComprehensiveExample::source()->read($roots)->extensions;

    expect($extensions)->toHaveCount(2)
        ->and($extensions[0])->toBeInstanceOf(ExtensionBlueprint::class)
        ->and($extensions[0]->layout?->tabs)->toBe([])
        ->and($extensions[0]->layout?->sidebar[0]->fields[0]->field)->toBe('tag')
        ->and($extensions[1]->layout)->toBeNull();
});

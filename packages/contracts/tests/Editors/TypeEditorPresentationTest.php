<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Editors;

use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorLayout;
use Cbox\Cms\Contracts\Editors\EditorPlacement;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\EditorSection;
use Cbox\Cms\Contracts\Editors\EditorTab;
use Cbox\Cms\Contracts\Editors\EditorText;
use Cbox\Cms\Contracts\Editors\EditorUi;
use Cbox\Cms\Contracts\Editors\EditorWidth;
use Cbox\Cms\Contracts\Editors\InvalidTypeEditor;
use Cbox\Cms\Contracts\Editors\SelectDisplay;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Testkit\Editors\SampleEditors;

/*
 * The presentation members of a type editor descriptor (gap G2, B2-T72): the layout places every
 * top-level field exactly once and nothing else, the texts per locale are each locale once and
 * sorted, the hints belong to the bases that take them, a default never belongs to a group or
 * rich text, and a reader's view keeps the layout of the fields kept.
 */

function presentationField(string $path, EditorBase $base, ?EditorUi $ui = null, ?JsonDocument $default = null, ?EditorRepeat $repeat = null): EditorField
{
    return new EditorField(
        path: $path,
        handle: new FieldHandle($path),
        namespace: null,
        parent: null,
        fieldType: $base->value,
        base: $base,
        label: ucfirst($path),
        description: null,
        presence: Presence::Optional,
        classification: ClassificationAccess::Public,
        agents: true,
        encrypted: false,
        choices: [],
        repeat: $repeat,
        rule: $base === EditorBase::Group ? null : new JsonDocument('{"kind":"text","maxLength":10}'),
        ui: $ui ?? new EditorUi,
        defaultValue: $default,
    );
}

/**
 * @param  list<string>  $paths
 */
function presentationLayout(array $paths): EditorLayout
{
    return EditorLayout::ofPaths($paths);
}

function presentationType(EditorLayout $layout): TypeEditor
{
    return new TypeEditor(
        new TypeId(Uuid7::lowestAt(1_700_000_000_000)),
        new TypeName('sample:thing'),
        'Thing',
        null,
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        null,
        [],
        [presentationField('a', EditorBase::Text), presentationField('b', EditorBase::Text)],
        $layout,
    );
}

it('holds the layout to every top-level field exactly once, and to nothing else', function (EditorLayout $layout, string $message): void {
    expect(static fn (): TypeEditor => presentationType($layout))->toThrow(InvalidTypeEditor::class, $message);
})->with([
    'a field left out' => [presentationLayout(['a']), 'leaves out the field b'],
    'a field placed twice across sections' => [new EditorLayout([new EditorTab(new FieldHandle('t'), 'T', [], [
        new EditorSection(new FieldHandle('s'), null, [], null, [], false, [new EditorPlacement('a', EditorWidth::Full), new EditorPlacement('b', EditorWidth::Full)]),
        new EditorSection(new FieldHandle('u'), null, [], null, [], false, [new EditorPlacement('a', EditorWidth::Full)]),
    ])], []), 'places the field a twice'],
    'a path the type does not have' => [presentationLayout(['a', 'b', 'c']), 'places c, which is not a top-level field'],
]);

it('refuses a tab, a section or a placement that repeats a handle or a path, and texts with a locale twice', function (): void {
    $section = static fn (string $handle): EditorSection => new EditorSection(new FieldHandle($handle), null, [], null, [], false, [new EditorPlacement('a', EditorWidth::Full)]);
    $tab = static fn (string $handle): EditorTab => new EditorTab(new FieldHandle($handle), 'T', [], [$section('s')]);

    expect(static fn (): EditorLayout => new EditorLayout([$tab('t'), $tab('t')], []))->toThrow(InvalidTypeEditor::class, 'the tab t twice')
        ->and(static fn (): EditorTab => new EditorTab(new FieldHandle('t'), 'T', [], [$section('s'), $section('s')]))->toThrow(InvalidTypeEditor::class, 'The tab t has the section s twice')
        ->and(static fn (): EditorLayout => new EditorLayout([], [$section('s'), $section('s')]))->toThrow(InvalidTypeEditor::class, 'The sidebar has the section s twice')
        ->and(static fn (): EditorSection => new EditorSection(new FieldHandle('s'), null, [], null, [], false, [new EditorPlacement('a', EditorWidth::Full), new EditorPlacement('a', EditorWidth::Half)]))->toThrow(InvalidTypeEditor::class, 'places the field a twice')
        ->and(static fn (): EditorTab => new EditorTab(new FieldHandle('t'), 'T', [new EditorText(new Locale('da'), 'x'), new EditorText(new Locale('DA'), 'y')], []))->toThrow(InvalidTypeEditor::class, 'has the locale da twice')
        ->and(static fn (): EditorText => new EditorText(new Locale('da'), ''))->toThrow(InvalidTypeEditor::class, '1 to 1000 characters');
});

it('sorts the texts by locale and canonicalises the locale', function (): void {
    $tab = new EditorTab(new FieldHandle('t'), 'T', [new EditorText(new Locale('en-gb'), 'Tee'), new EditorText(new Locale('da'), 'Te')], []);

    expect(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $tab->labels))->toBe(['da=Te', 'en-GB=Tee']);
});

it('lets only a select have a display, only a repeated group a summary, and never a group or rich text a default', function (): void {
    expect(static fn (): EditorField => presentationField('a', EditorBase::Text, new EditorUi(display: SelectDisplay::Radio)))->toThrow(InvalidTypeEditor::class, 'has a display, which only a select has')
        ->and(static fn (): EditorField => presentationField('a', EditorBase::Group, new EditorUi(summary: '{x}')))->toThrow(InvalidTypeEditor::class, 'has a summary, which only a repeated group has')
        ->and(presentationField('a', EditorBase::Group, new EditorUi(summary: '{x}'), repeat: new EditorRepeat(null, 3))->ui->summary)->toBe('{x}')
        ->and(static fn (): EditorField => presentationField('a', EditorBase::Group, default: new JsonDocument('{"kind":"constant","value":{}}')))->toThrow(InvalidTypeEditor::class, 'has a default, which a group or rich text never has')
        ->and(static fn (): EditorField => presentationField('a', EditorBase::RichText, default: new JsonDocument('{"kind":"constant","value":{}}')))->toThrow(InvalidTypeEditor::class, 'has a default, which a group or rich text never has')
        ->and(presentationField('a', EditorBase::Text, default: new JsonDocument('{"kind":"constant","value":"x"}'))->defaultValue?->value)->toBe('{"kind":"constant","value":"x"}')
        ->and(static fn (): EditorUi => new EditorUi(inputHint: str_repeat('a', 101)))->toThrow(InvalidTypeEditor::class, 'input hint')
        ->and(static fn (): EditorUi => new EditorUi(summary: ''))->toThrow(InvalidTypeEditor::class, 'summary')
        ->and(new EditorUi()->isEmpty())->toBeTrue()
        ->and(new EditorUi(hidden: true)->isEmpty())->toBeFalse();
});

it('gives a type without a declared layout one tab of one section with its paths at full width', function (): void {
    $layout = presentationLayout(['b', 'a']);

    expect($layout->paths())->toBe(['b', 'a'])
        ->and($layout->tabs[0]->handle->value)->toBe(EditorLayout::DEFAULT_TAB)
        ->and($layout->tabs[0]->sections[0]->handle->value)->toBe(EditorLayout::DEFAULT_SECTION)
        ->and($layout->tabs[0]->sections[0]->fields[1])->toEqual(new EditorPlacement('a', EditorWidth::Full))
        ->and($layout->sidebar)->toBe([]);
});

it('keeps, for a reader, the layout of the fields the reader sees, without the sections and tabs left empty, and the texts per locale', function (): void {
    $editor = SampleEditors::type(1);
    $public = $editor->visibleTo(ClassificationAccess::Public, agent: false);
    $agent = $editor->visibleTo(ClassificationAccess::Confidential, agent: true);

    expect($editor->layout->paths())->toBe(SampleEditors::PLACED_PATHS)
        ->and($public->layout->paths())->toBe(['label', 'mood', 'sizes', 'ext.probe.tag'])
        ->and(array_map(static fn (EditorTab $tab): string => $tab->handle->value, $public->layout->tabs))->toBe(['main', 'more'])
        ->and(array_map(static fn (EditorSection $section): string => $section->handle->value, $public->layout->tabs[0]->sections))->toBe(['names', 'health'])
        ->and($agent->layout->paths())->toBe(['label', 'memo', 'mood', 'extras', 'sizes', 'ext.probe.tag'])
        ->and($public->labels)->toEqual($editor->labels)
        ->and($public->field('label')?->labels)->toEqual($editor->field('label')?->labels)
        ->and($public->field('label')?->ui)->toEqual($editor->field('label')?->ui)
        ->and($public->field('label')?->defaultValue)->toEqual($editor->field('label')?->defaultValue)
        ->and($editor->visibleTo(ClassificationAccess::Public, agent: true)->field('mood')?->choices[0]->labels[0]->text)->toBe('Rolig');
});

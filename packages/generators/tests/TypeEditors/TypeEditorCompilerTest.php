<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\TypeEditors;

use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorPlacement;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\EditorSection;
use Cbox\Cms\Contracts\Editors\EditorTab;
use Cbox\Cms\Contracts\Editors\EditorText;
use Cbox\Cms\Contracts\Editors\EditorWidth;
use Cbox\Cms\Contracts\Editors\SelectDisplay;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeEditorCodecV1;
use Cbox\Cms\Generators\Codec\Domain\RecordContracts;
use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Generators\TypeEditors\Boundary\DefaultJson;
use Cbox\Cms\Generators\TypeEditors\Boundary\RuleJson;
use Cbox\Cms\Generators\TypeEditors\Domain\TypeEditorCompiler;
use LogicException;

/*
 * The type editor descriptor (PRD 11.12, 14.1, gap G1): the comprehensive example compiles to the
 * committed golden editor.json, as the generated codec writes it; the fields come in form order
 * with every group before the fields below it; each leaf field carries the value rule the record's
 * TypeScript module declares and a group its repeat; the title field is inferred from the owner's
 * text fields; and an extension field the blueprint requires is required on release.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

function editorCompiler(): TypeEditorCompiler
{
    return new TypeEditorCompiler(RuleJson::of(...), DefaultJson::of(...), RuleJson::condition(...));
}

function comprehensiveEditor(): TypeEditor
{
    return editorCompiler()->compile(ComprehensiveExample::compile()->types[0]);
}

/**
 * The editor as the golden file holds it: the codec's canonical JSON, pretty-printed.
 */
function editorJson(TypeEditor $editor): string
{
    $json = new TypeEditorCodecV1()->encode($editor, ClassificationAccess::Sensitive);

    return json_encode(json_decode($json, false, 64, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

/**
 * The editor of the app's type with the fields in YAML, each a list item, from a scratch root.
 */
function editorOf(string $fields, string $extension = ''): TypeEditor
{
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/app/thing.yaml', <<<YAML
        blueprint: 1
        kind: type
        type_id: 0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d21
        handle: thing
        label: Thing
        version: 1
        capabilities:
          history: full
          stages: draft-release
          localization: none
        fields:
        {$fields}
        YAML);
    $roots = [new SchemaRoot(new Owner('app'), $base, 'app')];

    if ($extension !== '') {
        SchemaFixtures::write($base.'/acme/thing.yaml', <<<YAML
            blueprint: 1
            kind: extension
            extends: 0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d21
            version: 1
            fields:
            {$extension}
            YAML);
        $roots[] = new SchemaRoot(new Owner('acme'), $base, 'acme');
    }

    return editorCompiler()->compile(DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read($roots)))->types[0]);
}

/**
 * @return list<string>
 */
function editorPaths(TypeEditor $editor): array
{
    return array_map(static fn (EditorField $field): string => $field->path, $editor->fields);
}

it('compiles the comprehensive example to the committed golden editor descriptor, as its codec writes it', function (): void {
    expect(editorJson(comprehensiveEditor()))
        ->toBe((string) file_get_contents(ComprehensiveExample::DIRECTORY.'/editor.json'), 'The editor descriptor of the comprehensive example differs from editor.json. Review the difference; when it is intended, write the new descriptor there.');
});

it('lists the fields in form order: the owner\'s in file order, each group before the fields below it, then each extender\'s', function (): void {
    $editor = comprehensiveEditor();

    expect(editorPaths($editor))->toBe([
        'name', 'support_email', 'summary', 'stock', 'weight', 'price', 'discontinued', 'launch_date', 'checked_at', 'colour', 'tags', 'body', 'care',
        'supplier', 'supplier.company', 'supplier.website', 'supplier.notes',
        'dimensions', 'dimensions.size', 'dimensions.width',
        'successor', 'accessories', 'manual', 'purchase_price',
        'ext.app.tax_code', 'ext.app.name',
    ])
        ->and($editor->field('supplier.company')?->parent)->toBe('supplier')
        ->and($editor->field('ext.app.name')?->namespace?->value)->toBe('app')
        ->and($editor->name->value)->toBe('shop:product')
        ->and($editor->titleField)->toBe('name')
        ->and(array_map(static fn (ExtensionVersion $extension): string => $extension->namespace->value.' '.$extension->version, $editor->extensions))->toBe(['app 2']);
});

it('gives each leaf field the value rule the record\'s TypeScript module declares, and a group its repeat and no rule', function (): void {
    $editor = comprehensiveEditor();

    expect($editor->field('name')?->rule?->value)->toBe('{"kind":"text","minLength":1,"maxLength":120}')
        ->and($editor->field('price')?->rule?->value)->toBe('{"kind":"decimal","precision":10,"scale":2,"min":"0.00","max":"99999999.99"}')
        ->and($editor->field('tags')?->rule?->value)->toBe('{"kind":"list","item":{"kind":"choice","choices":["new","sale","eco"]},"minItems":1,"maxItems":3,"distinct":true}')
        ->and($editor->field('body')?->rule?->value)->toBe('{"kind":"portable_text","styles":["normal","h2","h3"],"marks":["strong","em"],"lists":["bullet"],"links":["url"]}')
        ->and($editor->field('supplier')?->rule)->toBeNull()
        ->and($editor->field('supplier')?->repeat)->toBeNull()
        ->and($editor->field('dimensions')?->rule)->toBeNull()
        ->and($editor->field('dimensions')?->repeat)->toEqual(new EditorRepeat(1, 5))
        ->and($editor->field('dimensions.size')?->rule?->value)->toBe('{"kind":"choice","choices":["small","large"]}')
        ->and(array_map(static fn ($choice): string => $choice->value->value.'='.$choice->label, $editor->field('colour')->choices ?? []))->toBe(['red=Red', 'green=Green', 'blue=Blue']);
});

it('gives each field its presence, classification, agents flag and encryption as the catalog has them', function (): void {
    $editor = comprehensiveEditor();
    $of = static fn (string $path): array => [$editor->field($path)?->presence, $editor->field($path)?->classification, $editor->field($path)?->agents, $editor->field($path)?->encrypted, $editor->field($path)?->base];

    expect($of('name'))->toBe([Presence::Required, ClassificationAccess::Public, true, false, EditorBase::Text])
        ->and($of('summary'))->toBe([Presence::Optional, ClassificationAccess::Internal, false, false, EditorBase::LongText])
        ->and($of('supplier'))->toBe([Presence::Optional, ClassificationAccess::Confidential, true, true, EditorBase::Group])
        ->and($of('supplier.company'))->toBe([Presence::Required, ClassificationAccess::Confidential, true, true, EditorBase::Text])
        ->and($of('supplier.notes'))->toBe([Presence::Optional, ClassificationAccess::Confidential, false, true, EditorBase::LongText])
        ->and($of('ext.app.tax_code'))->toBe([Presence::RequiredOnRelease, ClassificationAccess::Internal, true, false, EditorBase::Text])
        ->and($of('ext.app.name'))->toBe([Presence::Optional, ClassificationAccess::Public, true, false, EditorBase::Text]);
});

it('infers the title field: the first required text field of the owner, else the first text field, else none', function (): void {
    $required = editorOf(<<<'YAML'
          - handle: teaser
            label: Teaser
            description: The teaser.
            type: text
            classification: public
          - handle: title
            label: Title
            description: The title.
            type: text
            required: true
            classification: public
        YAML);
    $first = editorOf(<<<'YAML'
          - handle: count
            label: Count
            description: The count.
            type: integer
            classification: public
          - handle: teaser
            label: Teaser
            description: The teaser.
            type: text
            classification: public
        YAML);
    $none = editorOf(<<<'YAML'
          - handle: count
            label: Count
            description: The count.
            type: integer
            classification: public
        YAML, <<<'YAML'
          - handle: title
            label: Title
            description: The title.
            type: text
            required: true
            classification: public
        YAML);

    expect($required->titleField)->toBe('title')
        ->and($first->titleField)->toBe('teaser')
        ->and($none->titleField)->toBeNull()
        ->and($none->field('ext.acme.title')?->presence)->toBe(Presence::RequiredOnRelease);
});

it('refuses to write the rule of a group, which only the record\'s module declares', function (): void {
    $type = ComprehensiveExample::compile()->types[0];
    $supplier = array_first(array_filter($type->fields, static fn (FieldDescriptor $field): bool => $field->handle->value === 'supplier')) ?? throw new LogicException('The example has no supplier.');
    $value = RecordContracts::fieldValue($supplier, $type);

    try {
        RuleJson::of($value);
    } catch (GenerationFailed $failed) {
        expect($failed->problems[0]->code)->toBe(GenerateErrorCode::InvalidOutput)
            ->and($failed->getMessage())->toContain('a form builds a group\'s rule from its nested fields');

        return;
    }

    throw new LogicException('The rule of a group was written.');
});

it('carries the layout, the labels per locale, the hints and the defaults of the comprehensive example (gap G2)', function (): void {
    $editor = comprehensiveEditor();
    $tabs = array_map(static fn (EditorTab $tab): string => $tab->handle->value.': '.implode(' | ', array_map(static fn (EditorSection $section): string => $section->handle->value.' '.implode(',', $section->paths()), $tab->sections)), $editor->layout->tabs);

    expect($tabs)->toBe([
        'general: identity name,colour,summary,body,care | supply supplier,purchase_price,stock,ext.app.tax_code',
        'details: measures weight,price,dimensions,accessories,successor,manual',
        'local: names ext.app.name',
    ])
        ->and(array_map(static fn (EditorSection $section): string => $section->handle->value.' '.implode(',', $section->paths()), $editor->layout->sidebar))->toBe(['status discontinued,launch_date,checked_at,tags', 'contact support_email'])
        ->and($editor->layout->tabs[0]->label)->toBe('General')
        ->and(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $editor->layout->tabs[0]->labels))->toBe(['da=Generelt', 'en=General'])
        ->and($editor->layout->tabs[0]->sections[0]->fields[0])->toEqual(new EditorPlacement('name', EditorWidth::TwoThirds))
        ->and($editor->layout->tabs[0]->sections[1]->collapsible)->toBeTrue()
        ->and($editor->layout->tabs[0]->sections[1]->instructions)->toBe('Who supplies the product and what it costs.')
        ->and($editor->layout->tabs[0]->sections[1]->fields[3])->toEqual(new EditorPlacement('ext.app.tax_code', EditorWidth::Half))
        ->and($editor->label)->toBe('Product')
        ->and(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $editor->labels))->toBe(['da=Produkt', 'en=Product'])
        ->and($editor->field('name')?->label)->toBe('Name')
        ->and(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $editor->field('name')->labels ?? []))->toBe(['da=Navn', 'en=Name'])
        ->and($editor->field('name')?->ui->inputHint)->toBe('The name on the shelf')
        ->and($editor->field('colour')?->ui->display)->toBe(SelectDisplay::Radio)
        ->and(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $editor->field('colour')->ui->instructionsByLocale ?? []))->toBe(['da=Vælg produktets farve.', 'en=Pick the colour of the product.'])
        ->and(array_map(static fn (EditorText $text): string => $text->locale->value.'='.$text->text, $editor->field('colour')->choices[0]->labels ?? []))->toBe(['da=Rød', 'en=Red'])
        ->and($editor->field('checked_at')?->ui->readOnly)->toBeTrue()
        ->and($editor->field('purchase_price')?->ui->hidden)->toBeTrue()
        ->and($editor->field('dimensions')?->ui->summary)->toBe('{size}: {width} mm')
        ->and($editor->field('support_email')?->ui->isEmpty())->toBeTrue()
        ->and($editor->field('stock')?->defaultValue?->value)->toBe('{"kind":"constant","value":0}')
        ->and($editor->field('price')?->defaultValue?->value)->toBe('{"kind":"constant","value":"0.00"}')
        ->and($editor->field('discontinued')?->defaultValue?->value)->toBe('{"kind":"constant","value":false}')
        ->and($editor->field('launch_date')?->defaultValue?->value)->toBe('{"kind":"token","token":"today"}')
        ->and($editor->field('checked_at')?->defaultValue?->value)->toBe('{"kind":"token","token":"now"}')
        ->and($editor->field('colour')?->defaultValue?->value)->toBe('{"kind":"constant","value":"red"}')
        ->and($editor->field('tags')?->defaultValue?->value)->toBe('{"kind":"constant","value":["new"]}')
        ->and($editor->field('name')?->defaultValue)->toBeNull();
});

it('gives a type without a layout the tab content with its fields in file order, and an extension without a fragment the tab extensions', function (): void {
    $editor = editorOf(<<<'YAML'
          - handle: teaser
            label: Teaser
            description: The teaser.
            type: text
            classification: public
          - handle: title
            label: Title
            description: The title.
            type: text
            required: true
            classification: public
        YAML, <<<'YAML'
          - handle: tag
            label: Tag
            description: The tag.
            type: text
            classification: public
        YAML);

    expect(array_map(static fn (EditorTab $tab): string => $tab->handle->value.' '.$tab->label.': '.implode(' | ', array_map(static fn (EditorSection $section): string => $section->handle->value.' '.implode(',', $section->paths()), $tab->sections)), $editor->layout->tabs))
        ->toBe(['content Content: fields teaser,title', 'extensions Extensions: acme ext.acme.tag'])
        ->and($editor->layout->sidebar)->toBe([])
        ->and($editor->layout->paths())->toBe(['teaser', 'title', 'ext.acme.tag']);
});

it('keeps only the placements of the fields a reader sees, and drops the sections and tabs left empty', function (): void {
    $editor = comprehensiveEditor()->visibleTo(ClassificationAccess::Public, agent: false);

    expect($editor->layout->paths())->toBe(['name', 'colour', 'body', 'care', 'weight', 'price', 'dimensions', 'accessories', 'manual', 'ext.app.name', 'discontinued', 'launch_date', 'tags', 'support_email'])
        ->and(array_map(static fn (EditorTab $tab): string => $tab->handle->value, $editor->layout->tabs))->toBe(['general', 'details', 'local'])
        ->and(array_map(static fn (EditorSection $section): string => $section->handle->value, $editor->layout->tabs[0]->sections))->toBe(['identity']);
});

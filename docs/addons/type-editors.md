---
title: Type editor descriptors
weight: 46
description: "The TypeEditors contract: the editor descriptor cms:generate writes per type, with its layout, labels per language, form hints and defaults, which the panel's forms render a type from, the queries type.list and type.describe that answer it filtered to the reader, the JSON contract type-editor.v1.json, the testkit's FakeTypeEditors and the shared suite TypeEditorsContract."
---

# Type editor descriptors

<!-- extension-point: Cbox\Cms\Contracts\Editors\TypeEditors -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\DescribesTypes -->
<!-- extension-point: Cbox\Cms\Testkit\Editors\TypeEditorsContract -->
<!-- extension-point: packages/contracts/resources/schemas/type-editor.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/type.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/type.list.result.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/type.describe.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/type.describe.result.v1.json -->

The panel's forms render a type from its editor descriptor (PRD 11.12, 14.1), never from the command's JSON Schema and never from a type named in the code (GUARDRAILS 2.4). `cms:generate` compiles one descriptor per type from the blueprints, next to the [type catalog](contracts/type-catalog.md), and the queries `type.list` and `type.describe` answer it to a reader with only the fields the reader may see. Everything on this page is `#[Experimental]`.

## The contract

`Cbox\Cms\Contracts\Editors\TypeEditors` has the lookups of the catalog:

| Method | Returns |
|---|---|
| `all(): list<TypeEditor>` | every descriptor, sorted by type name, each id and each name once |
| `find(TypeId $id): ?TypeEditor` | the descriptor of the type with the id, or `null` |
| `named(TypeName $name): ?TypeEditor` | the descriptor of the type with the name, such as `app:blog_post`, or `null` |

A `TypeEditor` holds what a form needs and the catalog does not:

- `id`, `name`, `label`, `description`, the owner's `version` and an `ExtensionVersion` per extender, and `capabilities`, so a form knows whether the type has stages to release.
- `titleField`, the path of the field a form shows an entry by: the first required text field of the owner in blueprint order, else the owner's first text field, or `null`.
- `fields`, the field tree as a flat list in form order: the owner's top-level fields in the order of the blueprint file, each group followed by the fields below it, then each extender's fields the same way, by namespace. A nested field names its group's path as `parent`, so a form rebuilds the tree while the contract stays without recursion, and every group comes before the fields below it. `field($path)` finds one and `below($parent)` gives the fields directly below a group, or the top-level fields for `null`.
- `layout`, the form's tabs and sidebar (gap G2): an `EditorLayout` of `EditorTab`s, each with a handle, a label with its `labels` per locale and `EditorSection`s, and the sidebar's sections. A section has a handle, an optional label and instructions, each with its texts per locale, `collapsible`, and its `fields`: `EditorPlacement`s of a top-level field's path and an `EditorWidth`, `Full`, `Half`, `Third` or `TwoThirds`. Every top-level field of the descriptor is placed exactly once, nothing else is placed, and the fields below a group are never placed, because the group's input holds them; the descriptor refuses a layout that breaks this. `cms:generate` composes it from the blueprint's `layout` and the fragments of its extensions, as [blueprint schema v1](blueprint-v1.md#layout) describes, and a type without a layout gets the tab `content` with one section.
- `labels` and `descriptions`, the type's label and description per locale the blueprint names, as `EditorText`s with a `Locale` and a text, sorted by locale; empty when the blueprint gives one text. A form shows the text of the panel's language and falls back to the plain `label` or `description`.

An `EditorField` is one input:

- `path` addresses it: the handle of a top-level field of the owner, `ext.<namespace>.<handle>` for a top-level extension field, and the parent's path, a dot and the handle inside a group. `handle`, `namespace` and `parent` are the parts.
- `fieldType` is the field type as the blueprint names it, a core field type or `<namespace>:<handle>` for an [addon's](field-types.md), and `base` the `EditorBase` whose form the value takes, one of the eleven core field types; a form picks its input by the base.
- `label` and `description` are the blueprint's; the description is the help text of the input.
- `presence` is what the generated validator requires: `Required` at every write, `RequiredOnRelease` for an extension field the blueprint requires (PRD 11.12, point 1), else `Optional`.
- `classification`, `agents` and `encrypted` are the field's as the catalog has them, and `readableBy()` is the rule the queries filter by.
- `choices` are the options of a select with their labels, in the blueprint's order; `targets` are the type names `<owner>:<handle>` a relation's targets may have, in the blueprint's order, so a picker searches entries of those types, and empty for any other base; `repeat` is how a group repeats, its `minItems` and `maxItems`, or `null`.
- `rule` is the field's value rule in the form of the generated validation runtime: a `JsonDocument` holding the `ValueRule` that the type's generated TypeScript record module declares for the field, so a form validates with the same interpreter. A group has no rule; a form builds its `object` rule, or its `list` rule when it repeats, from the fields below it and `repeat`.
- `labels` and `descriptions` are the field's label and description per locale, as the type's are, and a choice carries its `labels` too.
- `ui` is an `EditorUi`, the hints the blueprint's `ui` gives the input: `instructions` and `inputHint`, each with its texts per locale, `readOnly`, `hidden`, the `SelectDisplay` of a select (`Radio`, `Buttons` or `Dropdown`, only for the base select) and the `summary` template of a repeated group, which names the group's nested fields in braces. A field without hints has an empty `EditorUi`. The hints bind the form, not the kernel: a read-only or hidden field is still written by the commands and validated by its rule.
- `defaultValue` is the form's starting value from the blueprint's `default`, as one `JsonDocument`, or `null`: `{"kind":"constant","value":...}` with the value in the record's JSON form, or `{"kind":"token","token":"today"}` or `"now"` for a date or datetime the form takes from the clock when it opens. It is form only: a command never fills it in, and a group or rich text has none.
- `when` is the condition a conditional field is shown on (gap G3), as one `JsonDocument` holding the runtime's `ConditionRule`, such as `{"op":"eq","field":{"up":0,"key":"fixture_series_method"},"value":"fixture_manual"}`, or `null`, and `whenHidden` the `WhenHidden`, `Clear` or `Keep`; a field has both or neither. `field` names a sibling in the field's object by `key`, or `up` objects above it, an item of a repeated group being one object, so a form shows and hides the field with the runtime's `conditionHolds()` over the objects of the values it holds, and clears the input it hides when it is `Clear`. While a field is hidden it is never required, and its `presence` holds while it is shown. See [Conditional fields](blueprint-v1.md#conditional-fields).

`visibleTo(ClassificationAccess $access, bool $agent)` is what the queries answer a reader with: the fields the reader's classification access allows and, for an agent, that the blueprint opens to agents (PRD 2.31, 12.2), the fields below a group only while the group is seen, the title field only while its field is, and the layout with only the placements of the fields kept, without the sections and tabs that are left empty. Each of these types refuses a value that breaks its rules with `InvalidTypeEditor`.

## The queries

`type.list` answers every descriptor, sorted by type name, and `type.describe` the descriptor of the type named, or `null` when the installation has no type of that name. Both are `ActorQuery`s: every actor may run them without a permission, because they tell an actor only the shape of the types, and the anonymous principal may not (see [queries](queries.md)). They run on REST, in the panel's Inertia pages and on MCP, where an agent sees only the fields the blueprint opens to agents; both read no table, and each costs one unit.

Their results implement `Cbox\Cms\Contracts\Pipeline\DescribesTypes`, the sibling of `ReadsContent` for descriptors: the query pipeline takes the descriptors from `editors()`, gives each back as the reader may see it through `visibleTo()`, and puts them back with `withEditors()`, which holds exactly the descriptors it is given. So not even the label of a field the reader may not read leaves the pipeline, and a result of your own that carries descriptors gets the same filtering by implementing the contract.

## The JSON contracts

The descriptor's JSON form is [`type-editor.v1.json`](../../packages/contracts/resources/schemas/type-editor.v1.json), read and written only by the generated `TypeEditorCodecV1` (GUARDRAILS 2.2): keys sorted, every key present, the capabilities, choices, extensions, repeat, layout with its tabs, sections and placements, and each field's `ui` as objects, every text per locale as a list of `{"locale", "text"}` objects sorted by locale, each leaf field's `rule` as the runtime's `ValueRule` object, its `default` as the object above or `null`, and its `when` as the runtime's `ConditionRule` object or `null` with `when_hidden` `clear`, `keep` or `null`. `composer generate:protocol` writes its TypeScript, `TypeEditorV1` with `validateTypeEditorV1()`, and a copy of the validation runtime into `js/ui-kit/src/generated/protocol`, so the component kit's form validates a value against a field's `rule` with the runtime's `validate()`. The two queries have their schemas in `packages/core/resources/schemas/queries` as [query JSON](query-json.md) describes: `type.list.v1.json` carries nothing, `type.describe.v1.json` the type's `name`, and the results, `type.list.result.v1.json` and `type.describe.result.v1.json`, embed the descriptors as documents of `type-editor.v1.json`, bound with `ValueBinding::embedded()` so the result codecs hold the descriptor as its class and write and read it inline through its own codec.

## The generated class

`cms:generate` writes `GeneratedTypeEditors`, the implementation of the contract with a `TypeEditor` per type, next to `GeneratedTypeCatalog` in the PHP directory, and the generated service provider binds `TypeEditors` to it. The golden descriptor of the comprehensive example is `packages/generators/tests/Descriptor/Fixtures/Comprehensive/editor.json`, and its golden class is in the `Generated` directory beside it. The layout, the texts per locale, the hints and the defaults are the only output of the presentation keys: a blueprint change that touches only them changes the editor descriptor and nothing else `cms:generate` writes, which `packages/generators/tests/Descriptor/PresentationOnlyTest.php` holds it to.

## The fake and the shared suite

`Cbox\Cms\Testkit\Editors\FakeTypeEditors` holds the descriptors a test gives it, and `SampleEditors` builds sample types with fields of every classification and both agents flags, a select, a group once and repeated and an extension field, so a test of code that must work for any type needs no application. This example is in the `Unit` suite:

<!-- example: examples/Unit/Editors/TypeEditorsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Editors\FakeTypeEditors;
use Cbox\Cms\Testkit\Editors\SampleEditors;

// A form renders a type from its editor descriptor, never from a type named in the code. A test
// hands the fake descriptors to the code under test, and filters them as the queries type.list
// and type.describe do for a reader: with visibleTo().

/**
 * The paths of the inputs a form shows a reader, in form order: every field the reader may see,
 * and the fields below a group only while the group is seen.
 *
 * @return list<string>
 */
function formInputs(TypeEditors $editors, TypeName $type, ClassificationAccess $access, bool $agent): array
{
    $editor = $editors->named($type);

    if (! $editor instanceof TypeEditor) {
        return [];
    }

    return array_map(static fn (EditorField $field): string => $field->path, $editor->visibleTo($access, $agent)->fields);
}

it('shows a reader the inputs of the fields the reader may see', function (): void {
    $editors = new FakeTypeEditors(...SampleEditors::many(2));
    $card = new TypeName('sample:card_1');

    expect(formInputs($editors, $card, ClassificationAccess::Public, agent: false))
        ->toBe(['label', 'mood', 'sizes', 'sizes.size', 'ext.probe.tag'])
        ->and(formInputs($editors, $card, ClassificationAccess::Sensitive, agent: false))
        ->toBe(SampleEditors::PATHS)
        ->and(formInputs($editors, $card, ClassificationAccess::Sensitive, agent: true))
        ->toBe(['label', 'memo', 'mood', 'extras', 'extras.count', 'sizes', 'sizes.size', 'ext.probe.tag'])
        ->and(formInputs($editors, new TypeName('sample:card_9'), ClassificationAccess::Sensitive, agent: false))
        ->toBe([]);
});

it('carries per leaf field the value rule of the validation runtime, and per group how it repeats', function (): void {
    $editor = new FakeTypeEditors(SampleEditors::type(1))->all()[0];
    $sizes = $editor->field('sizes');

    expect($editor->titleField)->toBe('label')
        ->and($editor->field('label')?->rule?->value)->toBe('{"kind":"text","minLength":1,"maxLength":120}')
        ->and($sizes?->rule)->toBeNull()
        ->and([$sizes?->repeat?->minItems, $sizes?->repeat?->maxItems])->toBe([1, 5])
        ->and(array_map(static fn (EditorField $field): string => $field->path, $editor->below('sizes')))->toBe(['sizes.size']);
});
```

The shared suite `Cbox\Cms\Testkit\Editors\TypeEditorsContract` holds a `TypeEditors` to its lookups and to the rules a form relies on: the field tree is flat in form order with every group before the fields below it, a leaf field carries a value rule of the runtime and a group none, a select has its choices, a relation its target types with an id rule, the title field is a top-level text field of the owner, an extension field belongs to a declared extender and only it is required on release, and `visibleTo()` keeps for a reader exactly the fields the reader may see. The testkit runs it against the fake, the generators module against the workbench's generated class and the comprehensive example's, and this example runs it against the generated class in the `Contract` suite:

<!-- example: examples/Contract/Editors/GeneratedTypeEditorsContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Editors;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Testkit\Editors\TypeEditorsContract;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedTypeEditors;

/**
 * The shared TypeEditors suite against the editor descriptors cms:generate writes. In an
 * application the class is App\Cms\Generated\GeneratedTypeEditors; here it is the workbench's,
 * generated from its fixture schema. The generated class has a constructor without arguments, so
 * the suite needs no application.
 */
final class GeneratedTypeEditorsContractTest extends TestCase
{
    use TypeEditorsContract;

    #[Override]
    protected function editors(): TypeEditors
    {
        return new GeneratedTypeEditors;
    }
}
```

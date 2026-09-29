---
title: Runtime validators
weight: 45
description: "The runtime validators cms:generate writes per type for input from outside the repository: the TypeValidator contract, the rules in their vocabulary, and the kernel's InputValidator that checks input against them and returns field errors with paths and codes."
---

# Runtime validators

<!-- extension-point: Cbox\Cms\Contracts\Validation\TypeValidator -->

Inside the repository PHPStan and the TypeScript compiler catch every wrong field. At the boundaries they cannot: a command's fields from the REST API, MCP, the CLI or a sidecar arrive as JSON that no compiler has seen (PRD 11.12). There the kernel checks the input at run time, against rules generated from the same schema as the type table, the records and the TypeScript types.

## What cms:generate writes

`cms:generate` writes one validator per type to the directory `Validators` below the PHP directory, `app/Cms/Generated/Validators` in an application, named after the type's `TypeHandle` case: `ShopProductValidator` for `shop:product`. Each implements `Cbox\Cms\Contracts\Validation\TypeValidator`, whose `type()` gives the type's `TypeId` and `rules()` its `TypeRules`:

- the owner's fields, each a `FieldRules` with its handle, its `Presence` and its rules;
- each extender's fields in an `ExtensionRules` under its namespace, as the input holds them under `ext.<namespace>` (PRD 11.12 point 2);
- a group's nested fields below its `FieldRules`.

A field's rules start with its type rule, which says what the field holds: `string`, `integer`, `decimal` (a number written as a string, never a float, with its precision and scale), `boolean`, `date`, `datetime`, `object` for a group, `list` for a repeated group or a select with several choices, and `portable_text` for rich text. The rules that follow narrow it: `min_length` and `max_length`, `min` and `max`, `format` (`email` or `url`), `in` and `items_in` for the options of a select, `distinct`, `min_items` and `max_items`, and `styles`, `marks`, `lists` and `links` for rich text. The names are the enum `RuleName`; a rule that contradicts itself throws `InvalidRules`, because it can only come from wrong generated code.

`Presence` says when a field needs a value. An owner's required field is `Required`. An extension field that its blueprint marks required is `RequiredOnRelease`: the owner's code creates and revises entries without knowing the extension, so its value is required only when an entry is released (PRD 11.12 point 1, invariant 36). The caller says which it validates for with `ValidationStage::Write` or `ValidationStage::Release`.

## Checking input

The kernel's `Cbox\Cms\Core\Validation\Boundary\InputValidator` checks input against a type's rules, with `validate(TypeRules, $input)` or `validateWith(TypeValidator, $input)`. The input is what JSON decodes to, as arrays or as objects. Optional arguments give the stage and the path of the input in the command, such as `fields`, which every error's path then starts with.

It never throws for bad input. It returns a `ValidationReport` with every error it finds, at most `InputValidator::MAX_ERRORS`, each a `CatalogError` with the path of the value, such as `fields.dimensions[2].size` or `fields.ext.app.tax_code`, and the code of the rule it breaks, from the [error catalog](../reference/errors.md): `validation_required`, `validation_wrong_type`, `validation_unknown_field`, `validation_too_long` and the others. A command whose input has errors is rejected as a whole with `validation_failed`, the report's `code()`, and the errors say which fields to correct. Once a value has the wrong type its other rules are not checked, and a list with more items than its field allows is not checked item by item.

A key the type does not have is an error in the namespace it is given in: an owner's field at the top, an extender's under its namespace, a group's nested field in the group. So an extension field and an owner's field with the same handle never meet.

This example is in the `Unit` suite. The validator has the form `cms:generate` writes:

<!-- example-file: examples/Unit/Validation/NoteValidator.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Validation;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Override;

/**
 * The validator of a note type, in the form cms:generate writes one to app/Cms/Generated/Validators:
 * a required title of at most 80 characters, optional tags from a fixed list, and a field the
 * application adds in its namespace app, which a release requires.
 */
final readonly class NoteValidator implements TypeValidator
{
    #[Override]
    public function type(): TypeId
    {
        return TypeId::fromString('0199b1c2-3d4e-7f50-8a61-7b8c9d0e1f2a');
    }

    #[Override]
    public function rules(): TypeRules
    {
        return new TypeRules(
            [
                new FieldRules(
                    new FieldHandle('tags'),
                    Presence::Optional,
                    [
                        new Rule(RuleName::List),
                        new Rule(RuleName::Distinct),
                        new Rule(RuleName::ItemsIn, ['idea', 'task']),
                        new Rule(RuleName::MaxItems, ['2']),
                    ],
                ),
                new FieldRules(
                    new FieldHandle('title'),
                    Presence::Required,
                    [
                        new Rule(RuleName::String),
                        new Rule(RuleName::MaxLength, ['80']),
                    ],
                ),
            ],
            [
                new ExtensionRules(new FieldNamespace('app'), [
                    new FieldRules(
                        new FieldHandle('review_by'),
                        Presence::RequiredOnRelease,
                        [
                            new Rule(RuleName::Date),
                        ],
                    ),
                ]),
            ],
        );
    }
}
```

<!-- example: examples/Unit/Validation/NoteInputTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Examples\Unit\Validation\NoteValidator;

// A surface checks a command's fields, as JSON decoded them, against the type's validator before
// the command goes further. Bad input gives field errors with paths and codes, never an exception.

it('passes valid input and reports each broken rule with its path and code', function (): void {
    $validator = new InputValidator;
    $input = json_decode('{"tags": ["task", "later"], "colour": "red"}', true);

    $report = $validator->validateWith(new NoteValidator, $input, at: new FieldPath('fields'));

    expect($validator->validateWith(new NoteValidator, ['title' => 'Buy milk'])->passed())->toBeTrue()
        ->and($report->passed())->toBeFalse()
        ->and($report->code()->value)->toBe('validation_failed')
        ->and(array_map(static fn (CatalogError $error): string => $error->path?->toString().': '.$error->code->value, $report->errors))->toBe([
            'fields.tags[1]: validation_not_an_option',
            'fields.title: validation_required',
            'fields.colour: validation_unknown_field',
        ]);
});

it('requires the extension field only when the entry is released', function (): void {
    $validator = new InputValidator;
    $input = ['title' => 'Buy milk'];

    expect($validator->validateWith(new NoteValidator, $input, ValidationStage::Write)->passed())->toBeTrue()
        ->and($validator->validateWith(new NoteValidator, $input, ValidationStage::Release)->errors[0]->path?->toString())->toBe('ext.app.review_by')
        ->and($validator->validateWith(new NoteValidator, [...$input, 'ext' => ['app' => ['review_by' => '2026-10-01']]], ValidationStage::Release)->passed())->toBeTrue();
});
```

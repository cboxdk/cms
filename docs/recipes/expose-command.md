---
title: Expose a command in the panel
weight: 45
description: "Expose a command in the panel's command palette and generic command form: the surfaces of its write action, its JSON Schema and generated codec, the texts of the command and its fields in both catalogues, the permission a role needs, and the tests that hold the form to the schema and to the browser."
---

# Expose a command in the panel

A command is run from the panel without a page of its own (PRD 6.1, 13.4; GUARDRAILS 8): the [command palette](../addons/panel/command-palette.md) lists every command exposed on the Inertia surface that the person may run, and choosing one opens the generic [command form](../addons/panel/command-form.md), rendered from the command's JSON Schema. Exposing a command therefore takes no React: the action's surfaces, the schema with its codec, the texts, and a permission. [Add a kernel action](kernel-action.md) is the recipe for the command and its action; this one is the order to expose an existing command in.

## Inputs

- **command**: the command's name and contract version, such as `actor.activate` version 1, and its class, such as `Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor`.
- **document**: the members of the command's document, each with its type, its bounds, whether it is required and may be null, and its default; the form renders the subset of JSON Schema the [command form](../addons/panel/command-form.md#what-the-form-renders) lists, and refuses anything else.
- **texts**: the command's title and description and, for each member, its label and description, in English and Danish.

## Files

Paths below are relative to the root of the repository; `<command>` is the command's name, such as `actor.activate`, and `<Command>` its class's short name, such as `ActivateActor`.

| Path | What it holds |
|---|---|
| `packages/core/src/<Feature>/Actions/<Command>Action.php` | the write action, whose `#[Action]` lists `Surface::Inertia` beside `Surface::Rest`; the panel and REST stay in parity, so an action on Inertia alone fails `SurfaceParityTest` |
| `packages/core/resources/schemas/commands/<command>.v<n>.json` | the command's JSON Schema: `title` and `description`, `additionalProperties: false`, every member with a `description`, a patterned string with `examples`, and a member that is not required with a `default` |
| `packages/generators/src/Protocol/Domain/ProtocolSchemas.php` | the schema's binding in `commands()`: the codec's class, the command's class and the value bindings of its ids, value objects and enums |
| `packages/core/src/Codecs/Boundary/Generated/<Command>CodecV<n>.php` and `KernelCommandCodecs.php` (generated) | the codec, which carries the schema, and its entry in the list `CoreServiceProvider` registers; `composer generate:protocol` writes them |
| `workbench/resources/js/cms/generated/protocol/<Command>V<n>.ts` (generated) | the TypeScript validator of the command; `vendor/bin/testbench cms:generate` writes it |
| `js/panel/src/i18n/catalogues/en.json` and `da.json` | `panel.action.<command>.title` and `.description`, and `panel.action.<command>.field.<path>.label`, `.description` and `.option.<value>` per member |
| `docs/addons/<topic>.md` | the command's page in the documentation, with the schema as its extension point |

The command needs no code in the panel: `PanelRoutes` serves the form of every command the Inertia profile exposes, and `action.list` lists it for every person whose role names it.

## Steps

1. List `Surface::Inertia` in the action's `#[Action]`, beside `Surface::Rest`.
2. Write the schema, bind it in `ProtocolSchemas::commands()`, and run `composer generate:protocol` and `vendor/bin/testbench cms:generate`. Run `vendor/bin/testbench cms:build` in the workbench, so the registry exposes the action.
3. Add the texts to both catalogues and run `npm run lint:translations`.
4. Give a role the permission: the command's name among the role's permissions, through `role.create` or `role.set_permissions`, or `cms:access:bootstrap` for the first administrator.
5. Run the checks below, and `composer check` and `composer docs:check`. Record every new and changed test in `CHECKS-LOG.md`.

## Checks

- `tests/Feature/Surfaces/SurfaceContractTest.php` gets the command's Inertia case from its `#[Action]` alone (GUARDRAILS 9), and `packages/core/tests/Codecs/ExposedCommandCodecsTest.php` fails for an exposed command without a codec.
- `tests/Codecs/CommandCodecsTest.php` holds the PHP codec, the TypeScript validator and opis/json-schema to the same verdict on each rule of the schema.
- `js/ui-kit/tests/keyboard/SchemaForm.test.tsx` reads every kernel command schema into the form, so a keyword the form has no field for fails there, and `js/panel/tests/forms/rules.test.ts` holds the form's rules to the generated validator of every kernel command.
- `tests/Browser/Panel/PaletteTest.php` and `tests/Browser/Panel/CommandFormTest.php` open a command's form from the palette in Chromium and run it, with the shared page assertions.
- `composer check` and `composer docs:check` pass.

## Running example

The schema of `actor.activate`, which the form of the screenshots is rendered from:

<!-- example-file: packages/core/resources/schemas/commands/actor.activate.v1.json -->
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "actor.activate, contract version 1",
  "description": "Activates a pending actor (PRD 5.16, 6.4), the last step of a registration, once its credential is written: in one changeset the actor becomes active at its next version. An actor at another version than the caller read is version_conflict, and an actor that is not pending is refused with validation_failed. The PHP form is Cbox\\Cms\\Core\\Identity\\Domain\\Commands\\ActivateActor, and the generated codec ActivateActorCodecV1 reads it and writes its canonical JSON: every key, sorted, no whitespace.",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "actor",
    "version"
  ],
  "properties": {
    "actor": {
      "description": "The id of the pending actor to activate.",
      "$ref": "#/$defs/id"
    },
    "version": {
      "description": "The version of the actor the caller read.",
      "$ref": "#/$defs/version"
    }
  },
  "$defs": {
    "id": {
      "description": "A UUIDv7, such as 0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01.",
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
      "examples": [
        "0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"
      ]
    },
    "version": {
      "description": "The version of an aggregate that the caller read, an integer of 1 or more; the command is version_conflict when the aggregate is at another version when it commits.",
      "type": "integer",
      "minimum": 1
    }
  }
}
```

The schema as the form reads it, and its texts in both catalogues, in the `Unit` suite:

<!-- example: examples/Unit/Panel/CommandFormTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActivateActorCodecV1;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;

// A command is exposed in the panel by its JSON Schema (GUARDRAILS 2.2, PRD 6.1): the schema the
// command's generated codec carries is what the generic command form renders, so a command needs
// no page of its own. The form reads the schema's title and description as the form's, each
// property's description as its field's, and the examples of a patterned string as the field's
// hint; the panel's catalogue gives the translated texts when it has the keys of the command and
// its fields, and the form falls back to the schema's own. actor.activate.v1.json is such a schema:
// every property has a description, and both catalogues have its texts.

const EXAMPLE_ROOT = __DIR__.'/../../..';

/**
 * @return array<string, mixed>
 */
function commandFormSchema(): array
{
    return json_decode(ActivateActorCodecV1::SCHEMA, true, 32, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, string>
 */
function panelCatalogue(string $locale): array
{
    $decoded = json_decode((string) file_get_contents(EXAMPLE_ROOT.'/js/panel/src/i18n/catalogues/'.$locale.'.json'), true, 16, JSON_THROW_ON_ERROR);
    $texts = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $texts[$key] = $value;
        }
    }

    return $texts;
}

it('carries the schema the form is rendered from, with a title and a description of every field', function (): void {
    $schema = commandFormSchema();
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

    expect(ActivateActorCodecV1::COMMAND)->toBe('actor.activate')
        ->and($schema['title'])->toBe('actor.activate, contract version 1')
        ->and($schema['description'])->toBeString()
        ->and(array_keys($properties))->toBe(['actor', 'version'])
        ->and($schema['required'])->toBe(['actor', 'version'])
        ->and($schema['additionalProperties'])->toBeFalse();

    foreach ($properties as $key => $property) {
        expect($property)->toBeArray()->toHaveKey('description', message: "The field {$key} has a description.");
    }
});

it('reads the document the form submits, as every surface reads it', function (): void {
    $command = new ActivateActorCodecV1()->decode('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","version":1}', ClassificationAccess::Public);

    expect($command)->toBeInstanceOf(ActivateActor::class)
        ->and($command->actor->toString())->toBe('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01')
        ->and($command->version->value)->toBe(1);
});

it('has the texts of the command and each of its fields in both catalogues', function (string $locale): void {
    $texts = panelCatalogue($locale);
    $schema = commandFormSchema();
    $properties = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];

    expect($texts)->toHaveKeys(['panel.action.actor.activate.title', 'panel.action.actor.activate.description']);

    foreach ($properties as $field) {
        expect($texts)->toHaveKeys([
            'panel.action.actor.activate.field.'.$field.'.label',
            'panel.action.actor.activate.field.'.$field.'.description',
        ]);
    }
})->with(['en', 'da']);
```

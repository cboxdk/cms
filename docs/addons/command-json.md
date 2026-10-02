---
title: Command JSON
weight: 49
description: "The JSON form of the kernel's commands, one JSON Schema per command and version in packages/core/resources/schemas/commands, the generic fields of a revision, and the generated codecs every surface reads the commands with."
---

# Command JSON

<!-- extension-point: packages/core/resources/schemas/commands/actor.activate.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/actor.deactivate.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/actor.register.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/entry.create.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/entry.publish.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/entry.revise.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/entry.unpublish.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/placement.create.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/placement.set_window.v1.json -->
<!-- extension-point: packages/core/resources/schemas/commands/variant.release.v1.json -->
<!-- extension-point: Cbox\Cms\Core\Pipeline\Domain\CommandEncoder -->

A caller sends a command to a surface as a JSON document: the body of a REST call, the `command` argument of an MCP tool, the document of `cms:run` and the form of an Inertia page. The JSON form of each of the kernel's commands is fixed by a JSON Schema of draft 2020-12, one file per command and version in [`packages/core/resources/schemas/commands`](../../packages/core/resources/schemas/commands), named `<command>.v<version>.json`, and read and written only by the codec generated from it (GUARDRAILS 2.2). All of it is `#[Experimental]`.

| Command | Schema | Codec | PHP form |
|---|---|---|---|
| `entry.create` | `entry.create.v1.json` | `CreateEntryCodecV1` | [`CreateEntry`](entry-commands.md) |
| `entry.revise` | `entry.revise.v1.json` | `ReviseEntryCodecV1` | [`ReviseEntry`](entry-commands.md) |
| `variant.release` | `variant.release.v1.json` | `ReleaseVariantCodecV1` | [`ReleaseVariant`](release-command.md) |
| `entry.publish` | `entry.publish.v1.json` | `PublishEntryCodecV1` | [`PublishEntry`](publish-commands.md) |
| `entry.unpublish` | `entry.unpublish.v1.json` | `UnpublishEntryCodecV1` | [`UnpublishEntry`](publish-commands.md) |
| `placement.create` | `placement.create.v1.json` | `CreatePlacementCodecV1` | [`CreatePlacement`](placement-commands.md) |
| `placement.set_window` | `placement.set_window.v1.json` | `SetPlacementWindowCodecV1` | [`SetPlacementWindow`](placement-commands.md) |
| `actor.deactivate` | `actor.deactivate.v1.json` | `DeactivateActorCodecV1` | [`DeactivateActor`](actor-commands.md) |
| `actor.register` | `actor.register.v1.json` | `RegisterActorCodecV1` | [`RegisterActor`](actor-commands.md) |
| `actor.activate` | `actor.activate.v1.json` | `ActivateActorCodecV1` | [`ActivateActor`](actor-commands.md) |

## The documents

A document is an object of the command's keys in snake_case and no other key. Every key is required unless its schema gives it a default: `window` of `entry.publish` may be left out, which is `null`, `source` of `actor.deactivate` may be left out, which is `local`, and `responsible` of `actor.register` may be left out, which is `null`.

- An id is a UUIDv7 in a string, such as `"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"`, written back in lowercase.
- A version and a revision number are integers of 1 or more. `revision` of `entry.publish` is `null` for a type with stages none.
- A locale is a language tag, such as `da`, `en-GB` or `sr-Latn-RS`, written back with the language in lowercase, the script in title case and the region in uppercase.
- A window is an object of `live_from` and `live_until`, each RFC 3339 with an offset or `null`, written back in UTC with six decimals; `live_from` is before `live_until`, which the codec checks with the `TimeWindow` it builds.
- A slug of `placement.create` is an object of `locale` and `slug`: 1 to 255 characters without a slash or white space, and not `.` or `..`.
- `display_name` of `actor.register` is 1 to 200 characters without control characters that start and end with a character that is not white space, and `email` a local part, an `@` and a domain with a dot, at most 254 characters, without white space or control characters. Both are personal data (PRD 12.2), and no error message repeats them.

## The fields of a revision

`entry.create` and `entry.revise` work for any type (GUARDRAILS 2.4), so their `fields` are not a type's record but the fields of a revision in the input form the type's validator reads: an object of the owner's fields by handle, with an extender's fields under `ext` and its namespace, as in `{"headline": "Harbour opens", "ext": {"seo": {"keywords": ["harbour"]}}}`. A handle is lowercase snake_case of at most 63 characters, never `ext` or starting with `cms_`; a namespace is 1 to 20 lowercase letters and digits. A value is what JSON gives: a text, a decimal, a date and a date-time are strings, an integer is an integer, a boolean a boolean, `null` is null, a list is a list and a group or a map is an object of values by a key of 1 to 255 bytes. A number with a fraction is refused. The kernel checks the fields against the type's schema when it writes them, at the write stage, with `validation_failed` for a value the type refuses. The definitions under `$defs` that describe the fields are the same in both schemas.

## The codecs are generated

`composer generate:protocol` reads the schemas and writes the codecs into `packages/core/src/Codecs/Boundary/Generated`, bound to the command classes, with `KernelCommandCodecs`, which lists the `CommandCodec` of each: the command's name, its version, the codec and the schema, which the codec carries as `SCHEMA`. The core registers each under the container tag `CommandCodecs::TAG`, so REST, Inertia, MCP and the CLI read every kernel command, and cms:build and MCP describe it with its schema. `cms:generate` writes a TypeScript module per schema into `resources/js/cms/generated/protocol`, such as `CreateEntryV1.ts`, with the document's types and a validator, `validateCreateEntryV1()`, that refuses what the PHP codec refuses at the same value. Every command codec also implements `Cbox\Cms\Core\Pipeline\Domain\CommandEncoder`: `encodeCommand()` writes a command the kernel holds as canonical JSON with every field, and refuses a command of another class with `EncodingFailed`. The idempotency content hash of a command is the SHA-256 of its name, its version and that JSON, so the same document under the same key replays, and a command whose codec is not a `CommandEncoder` cannot be hashed.

`composer check:generated` fails when the committed codecs or TypeScript are not what the schemas generate, and the Arch suite fails on code outside a `Generated` directory that serialises a command itself. `decode()` refuses a document with `Cbox\Cms\Core\Codecs\Domain\DecodingFailed`: `json_malformed` for a document that is not a JSON object, and `json_invalid` with the path of the value for a key that is missing or unknown and a value that breaks a rule of the schema or of the command's class. A change of the JSON form is a change of the schema, and one that is not backwards compatible is a new version of the command with a schema file and a codec of its own.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/CommandJsonTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\CreateEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandEncoder;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;

// A caller sends entry.create as JSON: the entry's id, its type, its home node and the fields of
// its first revision by handle. The generated codec of entry.create.v1.json reads it into the
// command and writes it back as canonical JSON, and every surface describes the command with the
// schema the codec carries.

it('reads entry.create from JSON and writes it back as canonical JSON', function (): void {
    $codec = new CreateEntryCodecV1;
    $command = $codec->decode(
        '{"type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01",'
        .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","fields":{"headline":"Harbour opens","ext":{"seo":{"keywords":["harbour"]}}}}',
        ClassificationAccess::Public,
    );

    expect($command)->toBeInstanceOf(CreateEntry::class)
        ->and($command->fields->own->get(new FieldHandle('headline')))->toEqual(new TextValue('Harbour opens'))
        ->and($codec)->toBeInstanceOf(CommandEncoder::class)
        ->and($codec->encodeCommand($command))->toBe($codec->encode($command, ClassificationAccess::Sensitive))
        ->and($codec->encode($command, ClassificationAccess::Public))->toBe(
            '{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","fields":{"ext":{"seo":{"keywords":["harbour"]}},"headline":"Harbour opens"},'
            .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}',
        );
});

it('refuses a document that breaks the schema, and lists the codec of each kernel command', function (): void {
    $commands = array_map(static fn (CommandCodec $codec): string => $codec->command->value.' v'.$codec->version, KernelCommandCodecs::all());

    expect(static fn (): CreateEntry => new CreateEntryCodecV1()->decode(
        '{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","fields":{"Headline":"x"},"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}',
        ClassificationAccess::Public,
    ))->toThrow(DecodingFailed::class, 'is not a field handle')
        ->and($commands)->toContain('entry.create v1', 'entry.publish v1', 'actor.deactivate v1');
});
```

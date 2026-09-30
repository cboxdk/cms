---
title: Entry commands
weight: 43
description: "The kernel's commands entry.create and entry.revise: what a caller sends, how the kernel stores the revision and the type table's row for any type as its capabilities say, the events they emit, and how they are rejected."
---

# Entry commands

<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry -->

Content is written with two commands of the kernel, `entry.create` and `entry.revise`, version 1 of each (PRD 5.4, 6.4). They run through the [command pipeline](commands.md) like every other write, on every surface: `#[Action]` exposes them on REST, Inertia, MCP and the CLI, and jobs, seeds and subscribers call them too. Both work for any type from its schema; the kernel names no type and reads what it needs from the `TypeCatalog` the generated code binds (GUARDRAILS 2.4). Both commands and their events are `#[Experimental]`.

## The commands

`Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry` creates an entry with its first revision: the entry's `EntryId`, which the caller makes, the `TypeId` of its type, the `NodeId` of its home node, which owns its content and where its access is decided (PRD 5.10), and the `FieldValues` of its shared variant. A type of blueprint v1 has no localization, so `shared` is its one variant. The command expects the entry not to exist, so a repeat of a create with the same idempotency key replays the first receipt, and a create of an id that exists is `version_conflict`.

`Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry` writes the next revision of the shared variant and moves the variant's head to it: the entry, the `AggregateVersion` of the shared variant the caller read, and the variant's fields. A revision is a whole snapshot, so the command carries every field, not a change. `variant()` gives the `VariantRef` it revises, and `expectedVersions()` that variant at the version given.

<!-- example: examples/Unit/Entries/EntryCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;

// A surface, a job or a seed writes content with the kernel's two entry commands. The caller makes
// the entry's id, gives the type by its id and the home node the entry lives below, and sends every
// field of the revision; a revise names the version of the shared variant it read.

function exampleTitle(string $title): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('headline'), new TextValue($title))));
}

it('creates an entry that must not exist yet', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000101');

    $create = new CreateEntry(
        $entry,
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000102'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000103'),
        exampleTitle('Harbour opens'),
    );

    expect($create->expectedVersions()->reads)->toEqual([ReadVersion::absent($entry)])
        ->and(EntryCreated::type()->name)->toBe('entry.created');
});

it('revises the shared variant at the version the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000101');

    $revise = new ReviseEntry($entry, new AggregateVersion(3), exampleTitle('Harbour opens on Friday'));

    expect($revise->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($revise->expectedVersions()->of($revise->variant()))->toEqual(ReadVersion::at($revise->variant(), new AggregateVersion(3)))
        ->and(VariantRevised::type()->name)->toBe('variant.revised');
});
```

## What the kernel stores

The fields are validated against the type's rules in the schema version the code was generated from (invariant 4), at the write stage, where an extension field is never required (invariant 36). Then the commit writes, in the command's one transaction, as the app role under the caller's actor context:

| What | Where |
|---|---|
| the entry, on create: active, at version 1, with its type and home | `entries` |
| the head of the shared variant: the draft revision, the schema version, the release state `unreleased` on create, and the variant's version | `variant_heads` |
| for a type with `history: full`: the revision, a draft with its number, schema version and changeset, and its content | `revisions`, `revision_payloads` |
| for a type with `history: audit-only` or `none`: no revision, but the head snapshot, the current content with its revision number and schema version, updated in place | `head_snapshots` |
| the current state as a row of the type's table: the draft row for `stages: draft-release`, the released row for `stages: none` | `<owner>__<handle>` |

The content of a payload or a snapshot is the fields as JSON in the form the input validator reads: an object of the owner's fields by handle, with an extender's fields under `ext.<namespace>`, format version 1. The type table gets a value per top-level field in its column, and `NULL` for a field the revision leaves out. A draft row that holds the same values as the released row is removed, so a draft row exists only where a pending draft differs (PRD 4.1).

A changeset of `entry.create` emits `entry.created` about the entry and `variant.revised` about the variant; one of `entry.revise` emits `variant.revised`. Both carry ids and revision numbers, never a field's value (see [events](events.md)).

## Rejections

| Code | When |
|---|---|
| `version_conflict` | a create of an entry that exists; a revise of a variant at another version than the command gives, or of an entry that does not exist or the caller cannot reach; and either when another call changed what it read before it committed |
| `validation_failed` | the fields break the type's rules, the type is not a type of the installation, or the home node does not exist or is outside the caller's regions |
| `field_encryption_unavailable` | a value for a field classified confidential or above, which is stored only as ciphertext (PRD 12.2); until key management exists (PRD 12.3), such a field can only be left out or null |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

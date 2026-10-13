---
title: Entry commands
weight: 43
description: "The kernel's commands entry.create and entry.revise, in version 1 for one variant and version 2 for an entry's shared variant and its languages, variant.create_from for a translation and variant.delete to delete one: what a caller sends, how the kernel stores the revisions and the type table's rows for any type as its capabilities say, the events they emit, and how they are rejected."
---

# Entry commands

<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom -->
<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\DeleteVariant -->

Content is written with two commands of the kernel, `entry.create` and `entry.revise`, each in version 1, which writes one variant, and version 2, which writes an entry's shared variant and its languages (PRD 5.4, 6.4, 10.1). They run through the [command pipeline](commands.md) like every other write, on every surface: `#[Action]` exposes them on REST, Inertia, MCP and the CLI, and jobs, seeds and subscribers call them too. Both work for any type from its schema; the kernel names no type and reads what it needs from the `TypeCatalog` the generated code binds (GUARDRAILS 2.4). Both commands and their events are `#[Experimental]`.

## The commands

`Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry` creates an entry with its first revision: the entry's `EntryId`, which the caller makes, the `TypeId` of its type, the `NodeId` of its home node, which owns its content and where its access is decided (PRD 5.10), and the `FieldValues` of its shared variant. Version 1 of the entry commands, `entry.create`, `entry.revise`, `variant.release`, `entry.publish` and `entry.unpublish`, is the single-variant contract: it writes the shared variant alone, the one variant of a type with `localization: none`, and refuses a type with `localization: variants` for good with `validation_failed` naming version 2 of the command, which writes the languages' variants (PRD 10.1), so no write leaves an entry of such a type without a language. The command expects the entry not to exist, so a repeat of a create with the same idempotency key replays the first receipt, and a create of an id that exists is `version_conflict`.

`Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry` writes the next revision of the shared variant and moves the variant's head to it: the entry, the `AggregateVersion` of the shared variant the caller read, and the variant's fields. A revision is a whole snapshot, so the command carries every field the caller may read, not a change. `variant()` gives the `VariantRef` it revises, and `expectedVersions()` that variant at the version given.

## Version 2: the shared variant and the languages

A type with `localization: variants` has a variant per language beside the shared one: the language's variant holds the type's translatable fields, the shared variant the others, and each has its own revisions and its own release (PRD 10.1). Version 2 of the entry commands writes such a type, and works for a type with `localization: none` too, whose only variant is the shared one.

`Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry`, `entry.create` version 2, creates an entry with the first revision of each of its variants in one changeset: the entry's id, type and home node as in version 1, the `FieldValues` of the shared variant, and a `LocaleFields` per language, its `Locale` and the fields of the language's variant. A type with localization variants needs at least one language, so no entry of it is left without one; a language given for a type without localization is `validation_failed`, as is a type with localization variants without a language, at `locales`. The command expects the entry and each of its variants to be absent. Each variant's fields are validated against the fields that variant holds: a translatable field in the shared variant, or a shared field in a language's, is `validation_unknown_field`.

`Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants`, `entry.revise` version 2, writes the next revision of one or more variants and moves each head, in one changeset: the entry and a `VariantRevision` per variant, its `VariantKey`, `shared` or a language, the `AggregateVersion` of it the caller read and every field of its new revision. A save that changes the Danish text and the main image is one changeset with a revision in `da` and one in `shared` (PRD 10.3). A variant at another version than given, or one the entry does not have, is `version_conflict`, and a variant the type does not hold is `validation_failed`. A variant given twice is not a command.

Rights follow the locale (PRD 5.10, 10.3): a language's variant is authorized at the entry's home in that locale and the shared variant in every locale, so a translator whose grant is limited to `de` writes `de` alone and is `unauthorized` for `da` and for the shared variant. The [release command](release-command.md) has a version 2 the same way, which releases any variant.

In the type table, a language's row holds the language's fields and the shared fields (PRD 11.6), so a write of a language's variant takes the shared fields from the shared revision of the same changeset, or else from the shared variant's current revision, and a write of the shared variant rewrites the shared columns of every language's row. The shared variant of such a type has no row of its own.

<!-- example: examples/Unit/Entries/VariantCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
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
use Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseEntryVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantRevision;

// Version 2 of the entry commands writes a type whose languages are variants of the entry (PRD
// 10.1, 10.3): a create gives the shared variant's fields and each language's, a revise gives
// every variant it changes at the version the caller read, so one save changes the Danish text
// and a shared field in one changeset, and a release names the variant it releases.

function exampleField(string $handle, string $value): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle($handle), new TextValue($value))));
}

it('creates an entry with its shared variant and a Danish variant, all expected absent', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $create = new CreateLocalizedEntry(
        $entry,
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000102'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000103'),
        exampleField('channel', 'web'),
        [new LocaleFields(new Locale('da'), exampleField('headline', 'Havnen åbner'))],
    );

    expect(array_map(static fn (VariantRef $variant): string => $variant->variant->value, $create->variants()))->toBe(['shared', 'da'])
        ->and($create->expectedVersions()->of(new VariantRef($entry, VariantKey::of(new Locale('da')))))->toEqual(ReadVersion::absent(new VariantRef($entry, VariantKey::of(new Locale('da')))));
});

it('revises the Danish variant and the shared variant in one changeset, each at its own version', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');
    $da = new VariantRef($entry, VariantKey::of(new Locale('da')));

    $revise = new ReviseVariants($entry, [
        new VariantRevision(VariantKey::of(new Locale('da')), new AggregateVersion(3), exampleField('headline', 'Havnen åbner fredag')),
        new VariantRevision(VariantKey::shared(), new AggregateVersion(1), exampleField('channel', 'print')),
    ]);

    expect($revise->expectedVersions()->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(3)))
        ->and($revise->expectedVersions()->of(new VariantRef($entry, VariantKey::shared())))->toEqual(ReadVersion::at(new VariantRef($entry, VariantKey::shared()), new AggregateVersion(1)));
});

it('releases a revision of the Danish variant alone', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $release = new ReleaseEntryVariant($entry, VariantKey::of(new Locale('da')), new RevisionNumber(4), new AggregateVersion(4));

    expect($release->ref())->toEqual(new VariantRef($entry, VariantKey::of(new Locale('da'))))
        ->and($release->expectedVersions()->of($release->ref()))->toEqual(ReadVersion::at($release->ref(), new AggregateVersion(4)));
});
```

## Translations

`Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom`, `variant.create_from` version 1, creates a language's variant of an entry from a revision of another language's variant (PRD 9.2, 10.3): the entry, the new variant's `VariantKey`, the source's, the `RevisionNumber` of the source revision and the `AggregateVersion` of the source variant the caller read. The new variant's first revision is a copy of the source revision's fields, the starting point a translator works from, validated at the write stage against the fields a language's variant holds. The command expects the new variant to be absent, so two creates of one variant commit once, and is authorized at the entry's home in the new variant's locale, so a translator granted `de` creates `de` alone. It changes no public visibility: the new variant is released with `variant.release` version 2 or `entry.publish` version 2.

In the same changeset the kernel records the derivation `translated_from` (source variant, source revision) in `derivations`, partitioned per month and kept (PRD 4, 9.6). A later release of the source variant whose content is neither the source revision nor its published copy marks the derivation stale, in the release's changeset, so the translator sees that the text the translation was made from has changed. A release of the revision the translation was made from leaves it current. `entry.get` gives each head its `translated_from`, the variant and revision, or null for a head that is no translation, and `stale`.

<!-- example: examples/Unit/Entries/TranslationCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom;

// A translator starts the German variant of an entry from revision 3 of the Danish one: the new
// variant is expected absent and the Danish variant at the version the translator read. The
// kernel records the derivation translated_from (da, 3), and entry.get shows it as stale once the
// Danish variant is released with other content.

it('creates the German variant from a revision of the Danish one', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $create = new CreateVariantFrom($entry, VariantKey::of(new Locale('de')), VariantKey::of(new Locale('da')), new RevisionNumber(3), new AggregateVersion(4));

    expect($create->expectedVersions()->of($create->target()))->toEqual(ReadVersion::absent($create->target()))
        ->and($create->expectedVersions()->of($create->origin()))->toEqual(ReadVersion::at($create->origin(), new AggregateVersion(4)));
});
```

## Deleting a translation

`Cbox\Cms\Core\Entries\Domain\Commands\DeleteVariant`, `variant.delete` version 1, deletes one language's variant of an entry and leaves the entry, its shared variant and its other languages as they are (PRD 10.3): the entry, the language's `VariantKey` and the `AggregateVersion` of that variant the caller read. In one changeset the kernel withdraws the variant's head, which then has no released revision, removes its rows from the type's table, released and draft, and withdraws every placement of the entry in that locale, on every site, so their URLs answer 410, gone, on the [delivery API](../developers/delivery.md). The variant's revisions stay, as history. Withdrawn is sticky: only a reinstatement changes it (invariant 7), and a rebuild of the type table writes no row for a withdrawn language.

The command is authorized at the entry's home in the variant's locale, as `variant.release` version 2 is, so a translator granted `de` deletes `de` and is `unauthorized` for `da`. The placements are withdrawn wherever they are, also below nodes the caller's regions do not reach, because the deletion is decided on the entry's home (PRD 5.10). The shared variant, a type with `localization: none` and a variant withdrawn already are `validation_failed`. The changeset emits `variant.withdrawn` about the variant and `placement.visibility_changed` to `withdrawn` about each placement, so the [invalidation](subscribers.md) purges the entry's `e-` key and each placement's `n-` key, hard at the edge.

<!-- example: examples/Unit/Entries/DeleteTranslationTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\DeleteVariant;

// An editor deletes the Danish translation of an entry at the version of the Danish variant the
// editor read. The kernel withdraws the variant and the entry's placements in da, so their URLs
// answer 410, and leaves the entry, its shared variant and its other languages as they are.

it('deletes the Danish variant at the version the editor read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $delete = new DeleteVariant($entry, VariantKey::of(new Locale('da')), new AggregateVersion(5));

    expect($delete->ref()->aggregateKey())->toBe('variant:01936f5e-8a2b-7c3d-9e4f-000000000111:da')
        ->and($delete->expectedVersions()->of($delete->ref()))->toEqual(ReadVersion::at($delete->ref(), new AggregateVersion(5)));
});
```

## Fields the caller may not read

A caller sets only the fields it may read (PRD 2.31, 12.2), by the rule every read applies: a field classified above the call's classification access is closed to it, and for a call an agent issues, every field, and every nested field of a group, whose blueprint says `agents: false`. The command pipeline checks every revision a plan writes, so the rule holds for both commands and any other command that writes a revision:

- A value other than null for a closed field is `unauthorized`, with an error at the field's path below `fields`, such as `fields.fixture_organiser_email` or, for a nested field, `fields.<group>.<field>` with the item's index in a repeated group. Nothing is committed.
- A null, or no value, sets nothing. `entry.revise` replaces every field, so before validation each closed field takes the value of the revision the head moves from: the stored revision for a type with full history, the head snapshot for a type whose history is audit-only or none. A revise never erases what its caller cannot see. For an agent, a nested field closed to agents takes the value of the same group, item by item by position in a repeated group.
- A head written under another schema version than the type's cannot be read with the type's definitions, so a revise by a caller with closed fields is `validation_failed` until a caller who may read every field saves it under the current version.

## Agents and types with stages none

An agent prepares content and a person makes it public (invariant 18). A type with `stages: none` has no draft: a save writes the row the public reads wherever a placement of the entry is visible. So the command pipeline refuses a revision an agent saves of such a type, by the agent's credential or by an envelope that records an agent, with `agent_visibility_forbidden` while a placement of the entry is live or scheduled to open, in any locale on any site. Nothing is committed. A person may save it.

An agent may save an entry of such a type that no placement shows, such as a new entry or one whose placements are hidden, withdrawn or expired. Every placement of the entry then joins the call's reads, so a window a person opens on one of them before the save commits makes the save `version_conflict`. A type with stages keeps an agent's save as a draft, which the public does not read until a person releases it.

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

A revise numbers its revision after the variant's highest number, which is the published revision when a [release](release-command.md) wrote one after the draft.

A changeset of `entry.create` emits `entry.created` about the entry and `variant.revised` about the variant; one of `entry.revise` emits `variant.revised`. Both carry ids and revision numbers, never a field's value (see [events](events.md)).

## Rejections

| Code | When |
|---|---|
| `version_conflict` | a create of an entry that exists, or of a variant the entry has; a `variant.create_from` whose source is at another version than the command gives; a `variant.delete` of a variant at another version or one the entry does not have; a revise of a variant at another version than the command gives, or of an entry that does not exist or the caller cannot reach; and either when another call changed what it read before it committed |
| `validation_failed` | the fields break the type's rules, the type is not a type of the installation, the home node does not exist or is outside the caller's regions, a variant the type does not hold is written, or version 2 gives a language for a type without localization or none for a type with localization variants; for `variant.create_from` a type without localization, the shared variant as the new variant or the source, a variant from itself, a source revision the variant does not have, or one written under another schema version than the type's; for `variant.delete` the shared variant, a type without localization, or a variant withdrawn already |
| `unauthorized` | no role of the caller with the command's permission reaches the entry's home in the locale of a language's variant, or in every locale for the shared variant |
| `field_encryption_unavailable` | a value for a field classified confidential or above, which is stored only as ciphertext (PRD 12.2); until key management exists (PRD 12.3), such a field can only be left out or null |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

---
title: Variant head reader
weight: 39
description: "The VariantHeadReader contract: the head of one variant of an entry with its numbers, its release state and the fields of its draft, read on the caller's transaction under its actor context, the testkit's FakeVariantHeadReader and the shared suite VariantHeadReaderContract."
---

# Variant head reader

<!-- extension-point: Cbox\Cms\Contracts\Content\VariantHeadReader -->
<!-- extension-point: Cbox\Cms\Testkit\Content\VariantHeadReaderContract -->

A write action that revises a variant reads its head first (PRD 5.4, 5.6, 13.4): the version the command expects, the revision the head points at, the highest number any revision of the variant has, which the next revision follows, and the fields of the draft, which the new revision keeps but for what the action changes. An addon reads it through the contract `Cbox\Cms\Contracts\Content\VariantHeadReader`, so it needs no kernel-internal reader; the kernel's own `entry.get` reads every head of an entry the same way (see [entry queries](../entry-queries.md)). All the types on this page are `#[Experimental]`.

## The contract

The reader has one method, `head(EntryId $entry, VariantKey $variant): ?VariantHead`. A `VariantHead` holds:

- `entry`, `type` and `home`, the entry with its `TypeId` and its home `NodeId`.
- `variant`, the `VariantKey`: the shared variant, or a locale.
- `version`, the variant's `AggregateVersion`, which a command that revises the variant expects (see [commands and write actions](../commands.md)).
- `revision`, the `RevisionNumber` of the current draft revision; `latest`, the highest number any revision of the variant has, which the next revision follows; and `released`, the number of the released revision, or null when none is released. A release writes a published revision of its own after the revision it releases, so `latest` can be above `revision`.
- `state`, the `ReleaseState` of the head: `unreleased`, `released` or `withdrawn`.
- `fields`, the `FieldValues` of the draft revision as the type is now, or null when the revision was written under another schema version than the type's, whose fields the type cannot read (upcasters come with B3).

`head()` runs on the connection of the caller's open transaction, the command transaction of a write action's `resolve()` or the read transaction of a query action's `handle()`, under its actor context, so row level security decides what it sees (PRD 5.10): an entry whose home the actor's regions do not reach reads as absent. It is a fixed number of lookups by key, whatever else the entry holds, writes nothing and takes no lock; the commit of a command locks what was read and checks its version. It gives null when the entry is absent, when the entry has no head of the variant, and when the entry's type is not in the [type catalog](type-catalog.md), whose field definitions the fields are read with.

The default, `Cbox\Cms\Core\Entries\Adapter\ReaderVariantHeads`, is bound in `cbox-cms.contracts` and reads through the kernel's own entry and revision readers: two lookups, the entry with the head of the variant and the draft's content.

## In a write action

An addon's write action takes the reader in its constructor and reads the head in `resolve()`, before it plans. The command carries the version the caller saw, so the kernel rejects a stale head with `version_conflict` before `plan()` runs. This example is in the `Unit` suite:

<!-- example: examples/Unit/Content/ArticleHeadsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Testkit\Content\FakeVariantHeadReader;

// An addon's write action that revises a variant reads its head first: the version the command
// expects, the revision the head points at, the highest number any revision has, which the next
// revision follows, and the draft's fields, which the new revision keeps but for what the addon
// changes. The reader is the VariantHeadReader contract; a test gives the fake the head to read.

const EXAMPLE_ENTRY = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a31';

const EXAMPLE_HOME = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a32';

const EXAMPLE_TYPE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a33';

/**
 * The plan an addon makes to retitle the shared variant of an entry: the next revision after the
 * variant's highest number with the head's fields and the new title, and the head moved to it.
 * Null when the entry has no such head, or its draft cannot be read as the type is now, which
 * the kernel then rejects as version_conflict when the command expected the head.
 */
function retitlePlan(VariantHeadReader $heads, EntryId $entry, string $title): ?Plan
{
    $head = $heads->head($entry, VariantKey::shared());

    if (! $head instanceof VariantHead || ! $head->fields instanceof FieldValues) {
        return null;
    }

    $kept = array_values(array_filter($head->fields->own->fields, static fn (NamedValue $field): bool => $field->handle->value !== 'title'));
    $fields = new FieldValues(new FieldMap(...[...$kept, new NamedValue(new FieldHandle('title'), new TextValue($title))]), ...$head->fields->extensions);
    $next = $head->latest->next();

    return new Plan(
        new RevisionCreated($entry, $head->type, $head->variant, $next, $fields),
        new HeadMoved($entry, $head->variant, $head->revision, $next),
    );
}

it('reads the head of the shared variant and plans the next revision after the variant\'s highest number', function (): void {
    $entry = EntryId::fromString(EXAMPLE_ENTRY);
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue('A first title'))));
    $reader = new FakeVariantHeadReader()->with(new VariantHead(
        $entry,
        TypeId::fromString(EXAMPLE_TYPE),
        NodeId::fromString(EXAMPLE_HOME),
        VariantKey::shared(),
        new AggregateVersion(3),
        new RevisionNumber(2),
        new RevisionNumber(3),
        new RevisionNumber(3),
        ReleaseState::Released,
        $fields,
    ));

    $plan = retitlePlan($reader, $entry, 'A better title');
    $created = $plan?->mutations()[0] ?? null;
    $moved = $plan?->mutations()[1] ?? null;

    expect($reader->head($entry, VariantKey::shared())?->version)->toEqual(new AggregateVersion(3))
        ->and($created instanceof RevisionCreated ? [$created->revision->value, $created->fields->own->fields[0]->value] : null)->toEqual([4, new TextValue('A better title')])
        ->and($moved instanceof HeadMoved ? [$moved->from?->value, $moved->to->value] : null)->toBe([2, 4])
        ->and(retitlePlan($reader, EntryId::fromString(EXAMPLE_HOME), 'Nothing'))->toBeNull();
});

it('gives no fields for a head whose draft was written under another schema version than the type\'s', function (): void {
    $entry = EntryId::fromString(EXAMPLE_ENTRY);
    $reader = new FakeVariantHeadReader()->with(new VariantHead(
        $entry, TypeId::fromString(EXAMPLE_TYPE), NodeId::fromString(EXAMPLE_HOME), VariantKey::shared(),
        AggregateVersion::first(), new RevisionNumber(1), new RevisionNumber(1), null, ReleaseState::Unreleased, null,
    ));

    expect($reader->head($entry, VariantKey::shared())?->fields)->toBeNull()
        ->and(retitlePlan($reader, $entry, 'A title'))->toBeNull();
});
```

## The fake: FakeVariantHeadReader

`Cbox\Cms\Testkit\Content\FakeVariantHeadReader` holds the heads a test gives it with `with(VariantHead ...)`, each in place of one it held of the same entry and variant, and reads them back as the kernel's reader does. A head given with null fields is one whose draft was written under another schema version than the type's.

## The shared suite: VariantHeadReaderContract

A replacement for the kernel's reader runs `Cbox\Cms\Testkit\Content\VariantHeadReaderContract` in a PHPUnit class in its package's `tests/Contract` directory. `variantHeadReader()` gets the suite's heads, each of the suite's type `suite:note` homed on the node `HOME`, and returns a reader that holds them: a reader on a real database writes each head's entry, head and revisions first, and runs under a context whose regions reach `HOME`. The cases hold the reader to the numbers, the state and the fields of each head, to one head per variant, to nothing for an unknown entry and to null fields for a draft written under another schema version. The kernel runs it against the fake and against its reader on Postgres; this example runs it against the fake, in the `Contract` suite:

<!-- example: examples/Contract/Content/InMemoryVariantHeadReaderContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Content;

use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Testkit\Content\FakeVariantHeadReader;
use Cbox\Cms\Testkit\Content\VariantHeadReaderContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared VariantHeadReader suite against a reader. variantHeadReader() gets the suite's heads,
 * each of the suite's type homed on HOME: a replacement for the kernel's reader writes them to its
 * own store here and returns itself, under a context whose regions reach HOME. This example runs
 * it against the testkit's in-memory reader, which keeps the heads it is given.
 */
final class InMemoryVariantHeadReaderContractTest extends TestCase
{
    use VariantHeadReaderContract;

    #[Override]
    protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader
    {
        return new FakeVariantHeadReader()->with(...$heads);
    }
}
```

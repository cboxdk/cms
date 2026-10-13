---
title: Entry queries
weight: 45
description: "The kernel's entry reads entry.get and entry.list: what each gives, who may run them, how a page is read, how a head's fields are stripped to the reader's classification access, and how an entry out of reach is rejected."
---

# Entry queries

<!-- extension-point: packages/core/resources/schemas/queries/entry.get.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/entry.get.result.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/entry.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/entry.list.result.v1.json -->

The editor opens an entry and the content list reads a page with two queries of the kernel, version 1 of each (PRD 5.4, 5.7, 8.8, 13.4). They run through the [query pipeline](queries.md) like every other read, on REST, in the panel's Inertia pages and as MCP tools, and each has a JSON Schema for its document and one for its result in `packages/core/resources/schemas/queries`, read and written by generated codecs (see [query JSON](query-json.md)). The queries and their results are `#[Experimental]`.

| Query | What it gives | Who may run it |
|---|---|---|
| `entry.get` | one entry: its type, home node, lifecycle and version; every variant head, the shared variant first, each with its version, the numbers of its draft, highest and released revision, its release state, the schema version its draft was written under, the draft's fields, the variant and revision it was translated from with whether that translation is stale (PRD 10.3), and who changed the head last and when; and every placement of the entry the reader reaches, each with its node, version and locales, a locale with its slug, stored visibility, window and whether it is canonical | every actor; the anonymous principal may not |
| `entry.list` | a page of the entries the reader reaches, across types, newest first, each with its system columns: type, home node, lifecycle, the shared variant's release state, the last change and the version; and, as its fields, the title field its type's editor descriptor names with its value in the shared head's draft | every actor; the anonymous principal may not |

Both implement `Cbox\Cms\Contracts\Pipeline\ActorQuery`: what they read is bounded by the actor's own context, so they need no permission. An entry is within reach when the actor owns it or one of its regions reaches its home node, the same test as the entries table's own policy, and the kernel's reader adds it as the explicit access predicate of every statement (PRD 5.10), so an entry the actor sees only because it is released somewhere live is neither listed nor given. `entry.get` rejects an entry that is absent or out of reach with `entry_not_found`, at `entry`, and tells the two apart for no one. The kernel reads each head through the same reader the [variant head reader](contracts/variant-head-reader.md) contract gives addons.

## Fields and classification

The fields of each head are the draft revision's in the input form of [command JSON](command-json.md), read as the type is now: a text, a decimal, a date and a date-time are strings, an integer an integer, a boolean a boolean, a list a list and a group an object. The query pipeline leaves out every field classified above the reader's classification access, every field the type does not declare, and for an agent's credential every field its blueprint closes to agents (PRD 2.31, 12.2), before the codec writes the result; a sensitive field it gives an actor is read-audited. A head whose draft was written under another schema version than the type's has `schema_version` below the type's and empty fields, because the type cannot read them (upcasters come with B3). A language's variant made by [`variant.create_from`](entry-commands.md#translations) has `translated_from`, the source variant and revision, and `stale`, true once the source was released with other content since; every other head has `translated_from` null and `stale` false. `changed` says who changed the head last and when: the actor of the changeset that last gave the head its version, the time of that changeset, and the actor's display name when the reader may read the actor's profile, which is their own, or one their grants let them list with actor.list and personal access (PRD 12.2), and null otherwise; `changed` itself is null for a head no command has changed since the kernel began to keep it. The entry editor uses it to say who saved an entry when a save is refused with `version_conflict`.

`entry.list` gives each entry one field at most, so a list can show an entry by its title: the title field of its type, `title_field` of the [type editor descriptor](../../packages/contracts/resources/schemas/type-editor.v1.json) (the owner's first required text field, else its first text field), with its value in the shared head's draft, or its head snapshot for a type that keeps no full history. The fields are empty for a type without a title field, a draft that holds no value in it, or a draft written under another schema version than the type's, and the pipeline strips the field like any other: above the reader's classification access, for an agent where the blueprint closes it, and read-audited when it is sensitive. The page reads the titles in its one statement, whatever the number of types.

## Pages and filters

`entry.list` reads a page: at most `limit` entries, 1 to 100 and 50 when it is left out, newest first by id, after the entry whose id is `after`, or from the newest when `after` is null or left out. The result holds the entries and `next`, the id to send as `after` for the next page, or null when the page is the last. Three filters narrow the page, each for every value when null or left out: `type`, the type's id; `node`, the node whose subtree the entries are homed in, the node itself included, which lists nothing for a node the reader does not reach; and `state`, the release state of the shared variant. A page costs the rows it may return and `entry.get` costs its three statements, so each stays inside an actor's budget, and both cost the same statements whatever the number of entries, heads or placements (GUARDRAILS 4.1).

The last change of an entry is the newest of its creation, its revisions, its head snapshots and its releases and withdrawals, in UTC. The example is in the `Unit` suite:

<!-- example: examples/Unit/Entries/EntryQueriesTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryDetailCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GetEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListEntriesCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryList;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;

// The editor opens an entry with entry.get and the content list reads a page with entry.list. A
// caller sends each as JSON, read by the generated codec of its schema, and the surface writes the
// result with the codec of the result's schema, at the reader's classification access.

const EXAMPLE_DETAIL = '{"heads":[{"changed":{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a45","at":"2026-03-10T13:00:00.000000Z","name":"Ole Frost"},"fields":{"title":"Harbour"},"latest":2,"released":2,"revision":1,"schema_version":1,"stale":false,"state":"released","translated_from":null,"variant":"shared","version":3}],'
    .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","lifecycle":"active",'
    .'"placements":[{"id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a44","locales":[{"canonical":true,"locale":"da","slug":"havnen","visibility":"live",'
    .'"window":{"from":"2026-03-10T12:00:00.000000Z","until":null}}],"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","version":1}],'
    .'"type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a43","version":2}';

const EXAMPLE_PAGE = '{"entries":[{"changed_at":"2026-03-10T13:00:00.000000Z","fields":{"title":"Harbour"},"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41",'
    .'"lifecycle":"active","state":"released","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a43","version":2}],"next":null}';

it('reads entry.get from JSON and writes its result with every head, its fields and its placements', function (): void {
    $query = new GetEntryCodecV1()->decode('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41"}', ClassificationAccess::Public);
    $detail = new EntryDetailCodecV1()->decode(EXAMPLE_DETAIL, ClassificationAccess::Internal);

    expect($query)->toBeInstanceOf(GetEntry::class)
        ->and($detail)->toBeInstanceOf(EntryDetail::class)
        ->and($detail->heads[0]->state)->toBe(ReleaseState::Released)
        ->and($detail->heads[0]->latest->next()->value)->toBe(3)
        ->and($detail->heads[0]->changed?->name?->value)->toBe('Ole Frost')
        ->and($detail->placements[0]->locales[0]->slug->value)->toBe('havnen')
        ->and(new EntryDetailCodecV1()->encode($detail, ClassificationAccess::Internal))->toBe(EXAMPLE_DETAIL)
        ->and(static fn (): GetEntry => new GetEntryCodecV1()->decode('{}', ClassificationAccess::Public))->toThrow(DecodingFailed::class);
});

it('reads entry.list with its filters and writes a page of system columns and titles, newest first', function (): void {
    $codec = new ListEntriesCodecV1;
    $query = $codec->decode('{"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","state":"released","limit":20}', ClassificationAccess::Public);
    $page = new EntryListCodecV1()->decode(EXAMPLE_PAGE, ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ListEntries::class)
        ->and([$query->type, $query->node?->toString(), $query->state, $query->after, $query->limit])->toEqual([null, '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42', ReleaseState::Released, null, 20])
        ->and($codec->encode(new ListEntries(node: NodeId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42'), after: EntryId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41')), ClassificationAccess::Public))
        ->toBe('{"after":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","limit":50,"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","state":null,"type":null}')
        ->and($page)->toBeInstanceOf(EntryList::class)
        ->and($page->entries[0]->changedAt->format('H:i'))->toBe('13:00')
        ->and(array_map(static fn (FieldHandle $handle): string => $handle->value, $page->entries[0]->fields->own->handles()))->toBe(['title'])
        ->and(new EntryListCodecV1()->encode($page, ClassificationAccess::Public))->toBe(EXAMPLE_PAGE);
});
```

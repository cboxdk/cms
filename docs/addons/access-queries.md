---
title: Access queries
weight: 45
description: "The kernel's listing queries role.list, grant.list, actor.list and node.list: what each reads, who may run it, how a page is read, and how a profile's personal data is left out below personal access."
---

# Access queries

<!-- extension-point: packages/core/resources/schemas/queries/role.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/role.list.result.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/grant.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/grant.list.result.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/actor.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/actor.list.result.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/node.list.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/node.list.result.v1.json -->

The panel manages who may do what with four queries of the kernel, version 1 of each (PRD 5.10, 5.16). They run through the [query pipeline](queries.md) like every other read, on REST and in the panel's Inertia pages, and each has a JSON Schema for its document and one for its result in `packages/core/resources/schemas/queries`, read and written by generated codecs (see [query JSON](query-json.md)). The queries and their results are `#[Experimental]`.

| Query | What it gives | Who may run it |
|---|---|---|
| `role.list` | the roles, each with its handle, classification ceiling, permissions (sorted) and version | an actor with a role whose permissions name `role.list` |
| `grant.list` | the grants that have not ended on the nodes where a role of the actor whose permissions name `grant.list` reaches them, by the same nearest-grant rule as a command, each with its actor and the actor's profile, its role and the role's handle, its node and the node's path label, its effect, its locales (null for every locale) and its version | an actor with a role whose permissions name `grant.list` |
| `actor.list` | the staff actors, each with its state, version and profile | an actor with a role whose permissions name `actor.list` |
| `node.list` | the nodes the actor's regions reach, in tree order, each with its parent, kind, site, site handle and path label | every actor; the anonymous principal may not |

`node.list` implements `Cbox\Cms\Contracts\Pipeline\ActorQuery`: what it reads is bounded by the actor's own context, so it needs no permission (see [queries](queries.md)); so does `actor.me`, the query of one's own self, described in [panel pages](panel/pages.md). A node's path label has one segment per node from the root of its tree down to it, joined by `/`: the site's handle for the root of a site's tree, and below it the last segment of the node's route, or its kind when it has none, such as `north/news/section`.

## Pages

Each query reads a page: at most `limit` rows, 1 to 100 and 50 when it is left out, after the row whose id is `after`, or from the first row when `after` is null or left out. Roles, grants and actors are in the order of their ids, and nodes in tree order, a node before the nodes below it. The result holds the rows and `next`, the id to send as `after` for the next page, or null when the page is the last. A query costs the rows it may return, so a page stays inside an actor's budget.

## Profiles are personal data

An actor's display name and email are classified personal (PRD 12.2). A profile in a result is null when the reader may not read it at all: `grant.list` and `actor.list` give the profile of the reader's own actor, and of any other actor only when the reader's classification access allows personal. The profile they give is written by the result's codec at the classification access of the read, so a reader whose access is below personal gets it as an empty object, its values `Omitted`, and never sees the email of another actor. A document with a profile value for such a reader is refused. The schemas mark the two values with `"x-cms-classification": "personal"`, which the codec generator reads (see [query JSON](query-json.md)).

<!-- example: examples/Unit/Access/AccessQueriesTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GrantListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListNodesCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;

// The panel's pickers read with the listing queries: a page at a time, after the id of the last
// row the caller has. A profile is personal data, so the result's codec writes it only for a
// reader whose classification access allows personal, and refuses it in a document for anyone else.

const EXAMPLE_GRANTS = '{"grants":[{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","effect":"allow","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03",'
    .'"locales":null,"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04","node_label":"north/news","profile":{"display_name":"Ada Byline",'
    .'"email":"ada@example.com"},"role":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a05","role_handle":"desk","version":1}],"next":null}';

it('reads a page of nodes from an empty document, and refuses a limit above 100', function (): void {
    $codec = new ListNodesCodecV1;
    $query = $codec->decode('{}', ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ListNodes::class)
        ->and([$query->after, $query->limit])->toBe([null, 50])
        ->and($codec->encode(new ListNodes(limit: 20), ClassificationAccess::Public))->toBe('{"after":null,"limit":20}')
        ->and(static fn (): ListNodes => $codec->decode('{"limit":101}', ClassificationAccess::Public))->toThrow(DecodingFailed::class);
});

it('writes a grant\'s profile only for a reader whose access allows personal', function (): void {
    $codec = new GrantListCodecV1;
    $grants = $codec->decode(EXAMPLE_GRANTS, ClassificationAccess::Personal);
    $queries = array_map(static fn (QueryCodec $each): string => $each->name->value, KernelQueryCodecs::all());

    expect($grants)->toBeInstanceOf(GrantList::class)
        ->and($codec->encode($grants, ClassificationAccess::Personal))->toBe(EXAMPLE_GRANTS)
        ->and($codec->encode($grants, ClassificationAccess::Internal))->toContain('"profile":{}')
        ->and($grants->visibleTo(ClassificationAccess::Internal)->grants[0]->profile?->email)->toBe(Omitted::Field)
        ->and(static fn (): GrantList => $codec->decode(EXAMPLE_GRANTS, ClassificationAccess::Internal))->toThrow(DecodingFailed::class)
        ->and($queries)->toContain('actor.list', 'grant.list', 'node.list', 'role.list');
});
```

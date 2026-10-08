---
title: Node commands
weight: 45
description: "The kernel's commands for a site's tree: node.create, node.archive and node.set_route, what each reads and writes, the row level security they write through, and their rejections."
---

# Node commands

<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\CreateNode -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute -->

Nodes are the structure every site shares (PRD 5.8): site roots, sections, pages, lists, storage folders and mounts, never articles. A site's root comes with the site's registration ([site commands](site-commands.md)); everything below it is written by these three commands, each version 1 and on every surface.

| Command | What it does | Surfaces |
|---|---|---|
| `node.create` | creates a node of a kind below another node | rest, inertia, mcp, cli |
| `node.archive` | makes a node read-only structure | rest, inertia, mcp, cli |
| `node.set_route` | gives a node its route on a site in one language | rest, inertia, mcp, cli |

The commands, their events and their mutations are `#[Experimental]`.

<!-- example: examples/Unit/Structure/NodeCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchived;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchivedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChangedV1;

// A newsroom gives its site a section for sport: node.create puts the node below the site's root,
// node.set_route gives it the URL /sport in Danish, and node.archive closes it again once nothing
// below it is visible. The ids are the caller's, so a repeat with the same idempotency key is the
// same node.

const NODE_EXAMPLE_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000d01';

const NODE_EXAMPLE_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d02';

const NODE_EXAMPLE_SECTION = '01936f5e-8a2b-7c3d-9e4f-000000000d03';

it('creates a node below a parent and expects the node absent', function (): void {
    $create = new CreateNode(NodeId::fromString(NODE_EXAMPLE_SECTION), NodeId::fromString(NODE_EXAMPLE_ROOT), NodeKind::Section);
    $expected = $create->expectedVersions()->reads;

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $expected))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and(array_map(static fn (ReadVersion $read): bool => $read->existed(), $expected))->toBe([false])
        ->and($create->kind->value)->toBe('section');
});

it('puts a new node on its parent\'s path with its own label below it', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $parent = NodeId::fromString(NODE_EXAMPLE_ROOT);
    $path = new NodePath(NodeCreated::label($parent).'.'.NodeCreated::label($node));
    $created = new NodeCreated($node, $parent, 'section', $path);

    expect(NodeCreated::KINDS)->toBe(['section', 'page', 'list', 'storage'])
        ->and($created->path->value)->toBe($path->value)
        ->and($created->aggregate()->aggregateKey())->toBe('node:'.NODE_EXAMPLE_SECTION)
        ->and(fn (): NodeCreated => new NodeCreated($node, $parent, 'site', $path))
        ->toThrow(InvalidArgumentException::class, 'a node below another is one of section, page, list, storage');
});

it('gives the node a route the public asks for, which only a person may do', function (): void {
    $route = new SetNodeRoute(
        NodeId::fromString(NODE_EXAMPLE_SECTION),
        new AggregateVersion(1),
        SiteId::fromString(NODE_EXAMPLE_SITE),
        new Locale('da'),
        new RequestPath('/sport'),
    );
    $mutation = new NodeRouteSet($route->node, $route->site, $route->locale, $route->route->value);

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $route->expectedVersions()->reads))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and($mutation->route)->toBe('/sport')
        ->and($mutation->makesPublic())->toBeTrue()
        ->and(ErrorCode::NodeRouteTaken->entry()->http)->toBe(HttpStatus::Conflict);
});

it('archives a node at the version the caller read, and tells about it with ids alone', function (): void {
    $archive = new ArchiveNode(NodeId::fromString(NODE_EXAMPLE_SECTION), new AggregateVersion(2));
    $archived = new NodeArchived(3, new NodeArchivedV1($archive->node));
    $changed = new NodeRouteChanged(2, new NodeRouteChangedV1($archive->node, SiteId::fromString(NODE_EXAMPLE_SITE), new Locale('da')));

    expect(array_map(static fn (ReadVersion $read): ?int => $read->version?->value, $archive->expectedVersions()->reads))->toBe([2])
        ->and(NodeArchived::type()->name)->toBe('node.archived')
        ->and($archived->aggregate()->version)->toBe(3)
        ->and(NodeRouteChanged::type()->name)->toBe('node.route_changed')
        ->and(array_keys($changed->payload()->data()->fields()))->toBe(['locale', 'node', 'site']);
});
```

## node.create

`Cbox\Cms\Core\Structure\Domain\Commands\CreateNode` takes the `NodeId` of the new node, made by the caller, the `NodeId` of the node it goes below, and the `NodeKind`: `section`, `page`, `list` or `storage`. The new node's path in the tree is the parent's path with the node's own label, its id without the hyphens, below it, so every grant that reaches the parent reaches the new node too (PRD 5.10), and the issuing actor needs `node.create` on the parent.

The command expects the node absent, so a create of an id that exists is `version_conflict`, and a repeat with the same idempotency key is the same node. The action reads the parent at its version, so a parent archived or moved between the read and the commit is `version_conflict` as well.

## node.archive

`ArchiveNode` takes the node and the `AggregateVersion` the caller read. An archived node is read-only structure: `node.create` takes no parent that is archived, `node.set_route` no node that is, and the content already placed below it keeps the visibility it has.

Archiving never takes content off the public internet, which is what `entry.unpublish` is for, so the action reads, at the Clock's time, whether a placement below the node, the node itself included, is visible then or later, past the actor's regions, and refuses the command while one is. A mount below the node is not followed: it shows its source's placements, which stay where they are. The writer reads the placements once more in the commit, because a window opened on one of them does not change the node's version, and rolls the commit back if one became visible.

## node.set_route

`SetNodeRoute` takes the node, the `AggregateVersion` the caller read, the `SiteId`, the `Locale` and the route as a `RequestPath`: `/` or `/`-separated segments without a trailing slash. The route is the path the public asks for. A request resolves to the node with the longest route that is a prefix of its path, and the rest of the path is the slug of a placement below that node (PRD 5.9).

A route makes the node, and whatever is live below it, reachable from the public internet, so `NodeRouteSet` is a `ChangesPublicVisibility` mutation that always makes content public: the kernel refuses the command from an agent or a token with `agent_visibility_forbidden` (invariant 18). A person sets the route.

The route is an aggregate of its own, `node_route:<site>:<locale>:<route>`, which the action reads and the commit locks, so two commands that claim one route commit one after the other and the second is `version_conflict`. A route another node holds already is `node_route_taken`, which says which node holds it.

A node that has a route on the site in that language already is `validation_failed`: moving a route changes URLs that are out there, which needs the redirects of PRD 5.9, and those come with the redirect manager. Until then the kernel refuses the change instead of breaking the old URLs silently.

## What a command writes

The plans are `NodeCreated`, `NodeArchived` and `NodeRouteSet` (see [plans](plans.md)). The writers run as the app role under the call's actor context, so row level security decides what they may write:

| What | Where | The policy that takes it |
|---|---|---|
| the node, active, at version 1, below its parent on its path | `nodes` | `nodes_actor`, over `cms_access_reaches(path)` |
| the node set to the lifecycle `archived` at the next version | `nodes` | `nodes_actor` |
| the route of the node on the site in the language, and the node at the next version | `node_routes`, `nodes` | `node_routes_write`, over `cms_access_node(node_id)` |
| the changeset, its audit row with the node's key, and its receipt | the changeset tables, `audit`, `receipts` |  |
| `node.created` with the parent and the kind, `node.archived`, and `node.route_changed` with the site and the language, never the route, which is text | `events` |  |

A node outside the actor's regions is read through the owner lookup `cms_structure_node`, so a command on it is `unauthorized` and not a conflict with an aggregate that looks absent; the route's holder and the placements below a node are read past the regions the same way, because a route resolves to one node whoever reads it and archiving must not hide content the reader cannot see.

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the node, or the parent a node is created below, exists but the actor's grants do not reach it |
| `node_route_taken` | another node has the route on the site in that language |
| `agent_visibility_forbidden` | an agent or a token issued `node.set_route`, which makes the node public (invariant 18) |
| `validation_failed` | the parent does not exist, is archived or is a mount; the kind is `site` or `mount`; the node is archived already, is a site root or has a placement below it that is visible now or later; the site does not exist, does not publish in the language or does not hold the node; the node has a route on the site in that language already |
| `version_conflict` | the node has an id that exists, is at another version than the caller read, or the node or the route changed before the commit |

The [error reference](../reference/errors.md) explains each code.

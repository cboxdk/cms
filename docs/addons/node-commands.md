---
title: Node commands
weight: 45
description: "The kernel's commands for a site's tree: node.create, node.archive, node.set_route, node.move and node.set_label, what each reads and writes, the row level security they write through, and their rejections."
---

# Node commands

<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\CreateNode -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\ChangeNodeRoute -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\MoveNode -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\SetNodeLabel -->

Nodes are the structure every site shares (PRD 5.8): site roots, sections, pages, lists, storage folders and mounts, never articles. A site's root comes with the site's registration ([site commands](site-commands.md)); everything below it is written by these five commands, each version 1 and on every surface, and `node.set_route` also has a version 2, which moves a route.

| Command | What it does | Surfaces |
|---|---|---|
| `node.create` | creates a node of a kind below another node | rest, inertia, mcp, cli |
| `node.archive` | makes a node read-only structure | rest, inertia, mcp, cli |
| `node.set_route` | gives a node its route on a site in one language | rest, inertia, mcp, cli |
| `node.set_route` version 2 | gives a node its route, or moves the route it has and keeps the old one as a prefix redirect | rest, inertia, mcp, cli |
| `node.move` | moves a node with its subtree under another parent | rest, inertia, mcp, cli |
| `node.set_label` | sets the label a breadcrumb shows for a node, in one language | rest, inertia, mcp, cli |

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
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use Cbox\Cms\Contracts\Plans\Mutations\NodeMoved;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\MoveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeLabel;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchived;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchivedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChangedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMoved as NodeMovedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMovedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChangedV1;
use Cbox\Cms\Core\Structure\Domain\NodeLabel;

// A newsroom gives its site a section for sport: node.create puts the node below the site's root,
// node.set_route gives it the URL /sport in Danish, node.set_label the text a breadcrumb shows,
// node.move takes it below another section with everything below it, and node.archive closes it
// again once nothing below it is visible. The ids are the caller's, so a repeat with the same
// idempotency key is the same node.

const NODE_EXAMPLE_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000d01';

const NODE_EXAMPLE_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d02';

const NODE_EXAMPLE_SECTION = '01936f5e-8a2b-7c3d-9e4f-000000000d03';

const NODE_EXAMPLE_OTHER = '01936f5e-8a2b-7c3d-9e4f-000000000d04';

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

it('moves the node with its subtree below another parent, which makes content public only across sites', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $move = new MoveNode($node, new AggregateVersion(2), NodeId::fromString(NODE_EXAMPLE_OTHER));
    $path = new NodePath(NodeCreated::label(NodeId::fromString(NODE_EXAMPLE_ROOT)).'.'.NodeCreated::label($move->parent).'.'.NodeCreated::label($node));
    $within = new NodeMoved($node, NodeId::fromString(NODE_EXAMPLE_ROOT), $move->parent, $path, crossesSites: false, visible: true);
    $across = new NodeMoved($node, NodeId::fromString(NODE_EXAMPLE_ROOT), $move->parent, $path, crossesSites: true, visible: true);
    $moved = new NodeMovedEvent(3, new NodeMovedV1($node, $within->from, $within->to));

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $move->expectedVersions()->reads))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and($within->makesPublic())->toBeFalse()
        ->and($across->makesPublic())->toBeTrue()
        ->and(NodeMovedEvent::type()->name)->toBe('node.moved')
        ->and(array_keys($moved->payload()->data()->fields()))->toBe(['from', 'node', 'to']);
});

it('gives the node a label in one language, which no event repeats', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $label = new SetNodeLabel($node, new AggregateVersion(3), new Locale('da'), new NodeLabel('Sport'));
    $set = new NodeLabelSet($label->node, $label->locale, $label->label->value);
    $changed = new NodeLabelChanged(4, new NodeLabelChangedV1($node, $label->locale));

    expect($set->label)->toBe('Sport')
        ->and(NodeLabelSet::MAX_LENGTH)->toBe(200)
        ->and(fn (): NodeLabel => new NodeLabel(' Sport'))->toThrow(InvalidArgumentException::class)
        ->and(NodeLabelChanged::type()->name)->toBe('node.label_changed')
        ->and(array_keys($changed->payload()->data()->fields()))->toBe(['locale', 'node']);
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

Version 1 refuses a node that has a route on the site in that language already with `validation_failed`, because moving a route changes URLs that are out there. Version 2 moves it, below.

### Version 2: moving a route

`ChangeNodeRoute` (`node.set_route` version 2) takes the same document as version 1. For a node without a route on the site in that language it sets one, as version 1 does, with `NodeRouteSet`. For a node that has one it plans `NodeRouteMoved`, with the route the node had and the new one: the writer changes the node's row of `node_routes` and keeps the old route in `node_route_history`, a prefix redirect that points at the node. A request whose path starts with the old route then answers 301 to the same rest of the path below the node's current route (PRD 5.9), in one hop: the redirect reads the node's route as it is now, so it holds after several moves, and a slug below it that was renamed too is redirected to its current one in the same hop ([placement commands](placement-commands.md#renaming-a-placement)). A node that gets back a route it had loses that history row, because the live route answers it. The route the node has already is `validation_failed`.

A node was reachable at its old route already, so a move makes nothing public that was not, and an agent may move a route; the first route of a node is still refused from an agent with `agent_visibility_forbidden`.

<!-- example: examples/Unit/Structure/NodeRouteChangeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteMoved;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ChangeNodeRoute;

// A news site renames its section from /nyheder to /aktuelt: version 2 of node.set_route moves the
// section's route, and every URL below /nyheder answers 301 to the same path below /aktuelt.

it('moves a node\'s route with version 2 of node.set_route', function (): void {
    $section = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000921');
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000922');

    $change = new ChangeNodeRoute($section, new AggregateVersion(3), $site, new Locale('da'), new RequestPath('/aktuelt'));
    $moved = new NodeRouteMoved($section, $site, new Locale('da'), '/nyheder', '/aktuelt');

    expect($change->expectedVersions()->of($section))->toEqual(ReadVersion::at($section, new AggregateVersion(3)))
        ->and([$moved->from, $moved->to])->toBe(['/nyheder', '/aktuelt'])
        ->and(fn (): NodeRouteMoved => new NodeRouteMoved($section, $site, new Locale('da'), '/aktuelt', '/aktuelt'))->toThrow(InvalidArgumentException::class);
});
```


A mount takes a route like any other node: the route is how the mount is reached on its site, and the rest of a request's path is then looked up as a slug under the mount's source (PRD 5.9), so the source's placements show on the mount's site at the mount's route, identical with the source and canonical there ([mount commands](mount-commands.md)).

## node.move

`MoveNode` takes the node, the `AggregateVersion` the caller read, and the `NodeId` of the parent it goes below. The node's new path is the parent's path with the node's own label below it, and the kernel rewrites the path of every descendant in the same changeset, in one statement however many there are, so the subtree stays one and every grant that reaches the new parent reaches the moved nodes (PRD 5.10). Each descendant goes one version up with its path, so a command that read one of them before the move is `version_conflict`. Routes belong to their nodes and move with them, so a move within a site changes no URL.

The issuing actor needs `node.move` on both the parent the node leaves and the parent it goes below, and the action reads the node, both parents and every ancestor of the new parent, each once, so the commit locks the whole path the node lands under: two moves that would make a cycle together, A under B while B goes under A, each read the other's node, and the second finds it at another version and is `version_conflict`.

A move that lands under another site's root is refused while the node or a descendant has a route, because a route belongs to the site its node is in; take the routes off the subtree first. Such a move while a placement below the node is visible now or later makes that content public on the other site, so `NodeMoved` is a `ChangesPublicVisibility` mutation that makes content public exactly then, and the kernel refuses it from an agent or a token with `agent_visibility_forbidden` (invariant 18). The writer reads the placements once more in the commit when the move crosses sites and none was visible, because a window opened meanwhile does not change the node's version, and rolls the commit back if one became visible.

## node.set_label

`SetNodeLabel` takes the node, the `AggregateVersion` the caller read, the `Locale` and the `NodeLabel`: 1 to 200 characters without control characters, that start and end with a character that is not white space. A node has one label per language, so the command replaces the label the node had in that language, and the node goes one version up. A label is presentation, never an address: it changes no route and no URL, and `node.label_changed` carries the node and the language, never the text. Breadcrumbs and the panel's tree read the labels; every context does, the anonymous one included.

## What a command writes

The plans are `NodeCreated`, `NodeArchived`, `NodeRouteSet`, `NodeRouteMoved`, `NodeMoved` and `NodeLabelSet` (see [plans](plans.md)). The writers run as the app role under the call's actor context, so row level security decides what they may write, except the move, which rewrites a whole subtree through an owner function:

| What | Where | The policy that takes it |
|---|---|---|
| the node, active, at version 1, below its parent on its path | `nodes` | `nodes_actor`, over `cms_access_reaches(path)` |
| the node set to the lifecycle `archived` at the next version | `nodes` | `nodes_actor` |
| the route of the node on the site in the language, and the node at the next version | `node_routes`, `nodes` | `node_routes_write`, over `cms_access_node(node_id)` |
| a moved route: the node's route changed, the old route in the route history pointing at the node, and the node at the next version | `node_routes`, `node_route_history`, `nodes` | `node_routes_change` and the `node_route_history_*` policies, over `cms_access_node(node_id)` |
| the node below its new parent at the next version, and every descendant on its new path one version up, through the owner function `cms_structure_move_node`, which runs only in the transaction of a `node.move` changeset by the actor of the context | `nodes` | `nodes_owner_move`, over `cms_structure_moving()` |
| the label of the node in the language, inserted or replaced, and the node at the next version | `node_labels`, `nodes` | `node_labels_write` and `node_labels_change`, over `cms_access_node(node_id)` |
| the changeset, its audit row with the node's key, and its receipt | the changeset tables, `audit`, `receipts` |  |
| `node.created` with the parent and the kind, `node.archived`, `node.route_changed` with the site and the language, never the route, which is text, `node.moved` with the parent left and the parent gone below, and `node.label_changed` with the language, never the label | `events` |  |

A node outside the actor's regions is read through the owner lookup `cms_structure_node`, so a command on it is `unauthorized` and not a conflict with an aggregate that looks absent; the route's holder, the placements below a node, the ancestors of a node and the routed node of a subtree are read past the regions the same way, because a route resolves to one node whoever reads it, archiving must not hide content the reader cannot see, and a move must lock the path it lands under and weigh the routes of the whole subtree.

The invalidation subscriber receives `node.moved`, `node.route_changed` and `node.label_changed` and purges the `n-` key of the node, which every answer below the node depends on, old URLs and new, and for a move of every node below it, a batch of the subtree at a time, so a breadcrumb or a tree below any of them is rebuilt (see [subscribers](subscribers.md)).

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the node, the parent a node is created below, or a parent a node is moved from or to, exists but the actor's grants do not reach it |
| `node_route_taken` | another node has the route on the site in that language |
| `agent_visibility_forbidden` | an agent or a token issued `node.set_route`, which makes the node public, or a `node.move` that takes a visible placement to another site (invariant 18) |
| `validation_failed` | the parent does not exist, is archived or is a mount; the kind is `site` or `mount`; the node is archived already, is a site root or has a placement below it that is visible now or later; the site does not exist, does not publish in the language or does not hold the node; the node has a route on the site in that language already (version 1) or has the route asked for already (version 2); a moved node is a site root or archived, its new parent is the node itself or below it, a mount, archived or the parent already, or the move lands under another site while a node of the subtree has a route; a labelled node is archived |
| `version_conflict` | the node has an id that exists, is at another version than the caller read, or the node, the route, a parent or an ancestor of the new parent changed before the commit |

The [error reference](../reference/errors.md) explains each code.

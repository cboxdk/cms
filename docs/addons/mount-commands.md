---
title: Mount commands
weight: 46
description: "The kernel's commands for mounts: mount.create, which shows another node's placements on a site without copying them, and mount.set_override, which lets the site hide one entry there; what each reads and writes, the row level security on mount_overrides, how a hidden entry resolves, and their rejections."
---

# Mount commands

<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\CreateMount -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\SetMountOverride -->

A mount is a node that shows another node's placements on this site without creating rows for them (PRD 5.8): the national section appears under every regional site with no placement of its own. The rule is that a mount is identical with its source: same slugs, same visibility, canonical at the source. A site that needs its own slug or window makes a placement of its own instead ([placement commands](placement-commands.md)); a site that only needs to say no to one entry sets an exception on the mount. Both commands are version 1 and on every surface.

| Command | What it does | Surfaces |
|---|---|---|
| `mount.create` | creates a mount below a node, showing a source | rest, inertia, mcp, cli |
| `mount.set_override` | hides an entry on a mount, or shows it again | rest, inertia, mcp, cli |

The commands, their events and their mutations are `#[Experimental]`.

<!-- example: examples/Unit/Structure/MountCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\MountCreated;
use Cbox\Cms\Contracts\Plans\Mutations\MountOverrideSet;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateMount;
use Cbox\Cms\Core\Structure\Domain\Commands\SetMountOverride;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreated as MountCreatedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreatedV1;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSet as MountOverrideSetEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSetV1;

// A regional site shows the national section without copying a single placement: mount.create
// puts a mount below the regional root that shows the national section, and the regional desk
// hides one story on it with mount.set_override, which answers 404 at the regional URL while the
// national site keeps showing it. The ids are the caller's, so a repeat with the same idempotency
// key is the same mount.

const MOUNT_EXAMPLE_REGIONAL_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d11';

const MOUNT_EXAMPLE_MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000d12';

const MOUNT_EXAMPLE_NATIONAL = '01936f5e-8a2b-7c3d-9e4f-000000000d13';

const MOUNT_EXAMPLE_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000d14';

it('creates a mount below a parent showing a source, and expects the mount absent', function (): void {
    $create = new CreateMount(NodeId::fromString(MOUNT_EXAMPLE_MOUNT), NodeId::fromString(MOUNT_EXAMPLE_REGIONAL_ROOT), NodeId::fromString(MOUNT_EXAMPLE_NATIONAL));
    $expected = $create->expectedVersions()->reads;

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $expected))
        ->toBe(['node:'.MOUNT_EXAMPLE_MOUNT])
        ->and(array_map(static fn (ReadVersion $read): bool => $read->existed(), $expected))->toBe([false])
        ->and($create->source->toString())->toBe(MOUNT_EXAMPLE_NATIONAL);
});

it('puts the mount on its parent\'s path with its own label below it, and never names itself as the source', function (): void {
    $mount = NodeId::fromString(MOUNT_EXAMPLE_MOUNT);
    $parent = NodeId::fromString(MOUNT_EXAMPLE_REGIONAL_ROOT);
    $path = new NodePath(NodeCreated::label($parent).'.'.NodeCreated::label($mount));
    $created = new MountCreated($mount, $parent, NodeId::fromString(MOUNT_EXAMPLE_NATIONAL), $path);
    $event = new MountCreatedEvent(1, new MountCreatedV1($mount, $parent, $created->source));

    expect($created->aggregate()->aggregateKey())->toBe('node:'.MOUNT_EXAMPLE_MOUNT)
        ->and(MountCreatedEvent::type()->name)->toBe('mount.created')
        ->and(array_keys($event->payload()->data()->fields()))->toBe(['node', 'parent', 'source'])
        ->and(fn (): MountCreated => new MountCreated($mount, $parent, $mount, $path))
        ->toThrow(InvalidArgumentException::class, 'itself as its source');
});

it('hides an entry on the mount, which only a person may undo while the source shows the entry', function (): void {
    $mount = NodeId::fromString(MOUNT_EXAMPLE_MOUNT);
    $entry = EntryId::fromString(MOUNT_EXAMPLE_ENTRY);
    $hide = new SetMountOverride($mount, new AggregateVersion(1), $entry, true);
    $hidden = new MountOverrideSet($mount, $entry, hidden: true, visible: true);
    $shown = new MountOverrideSet($mount, $entry, hidden: false, visible: true);
    $quiet = new MountOverrideSet($mount, $entry, hidden: false, visible: false);
    $event = new MountOverrideSetEvent(2, new MountOverrideSetV1($mount, $entry, true));

    expect(array_map(static fn (ReadVersion $read): ?int => $read->version?->value, $hide->expectedVersions()->reads))->toBe([1])
        ->and($hidden->makesPublic())->toBeFalse()
        ->and($shown->makesPublic())->toBeTrue()
        ->and($quiet->makesPublic())->toBeFalse()
        ->and(VisibilityDecision::MountHidden->rung())->toBe(6)
        ->and(MountOverrideSetEvent::type()->name)->toBe('mount.override_set')
        ->and(array_keys($event->payload()->data()->fields()))->toBe(['entry', 'hidden', 'node']);
});
```

## mount.create

`Cbox\Cms\Core\Structure\Domain\Commands\CreateMount` takes the `NodeId` of the new mount, made by the caller, the `NodeId` of the node it goes below, and the `NodeId` of the source whose placements it shows. The mount's path in the tree is the parent's path with the mount's own label below it, so every grant that reaches the parent reaches the mount too, and the issuing actor needs `mount.create` on the parent (PRD 5.10). The source need not lie in the actor's regions: a mount shows only what the source shows the public, and the regional desk that mounts the national section holds no rights there.

The command expects the mount absent, so a create of an id that exists is `version_conflict`, and a repeat with the same idempotency key is the same mount. The action reads the parent, the source and every ancestor of the parent as aggregates, so the commit locks the path the mount lands under: a `node.move` that meanwhile puts the source above the parent changes the version of a node read, and the create is `version_conflict` instead of a mount below its own source. A source that is the parent or above it already is refused, as is a source that is a mount, because a mount shows a node that holds placements of its own, and a source or a parent that is archived.

A mount takes no node below it: `node.create` and `node.move` refuse a mount as the parent. It is archived like any node, and holds no placement of its own, so archiving it is never refused for visible content; the source keeps showing what it shows, at the mount's URLs too, because archiving changes no visibility (see [node commands](node-commands.md)).

## mount.set_override

`SetMountOverride` takes the mount, the `AggregateVersion` the caller read, the `EntryId` and `hidden`. Hidden, the entry answers 404 at the mount's URLs while its source keeps showing it: `path.resolve` reads the exception in the same lookup as the placement and decides `mount_hidden`, rung 6 of PRD 6.6, which the explanation names, and the delivery API answers `path_not_found`. Not hidden, the exception is taken off again and the mount shows the entry as its source does. The priority cap of PRD 5.8 is not in the command; it comes with curation.

The exception is decided on the mount's node (PRD 5.10), so the issuing actor needs `mount.set_override` there, and the mount goes one version up with each exception, so two commands that read the mount at one version commit one after the other. Hiding never makes content public. Taking the exception off an entry its source shows now or later does, so `MountOverrideSet` is a `ChangesPublicVisibility` mutation that makes content public exactly then, and the kernel refuses it from an agent or a token with `agent_visibility_forbidden` (invariant 18); an agent may hide. The action reads what the source shows of the entry past the actor's regions, and the writer reads it once more in the commit when the removal was planned as not public, because a window opened meanwhile does not change the mount's version, and rolls the commit back if the entry became visible.

## What a command writes

The plans are `MountCreated` and `MountOverrideSet` (see [plans](plans.md)). The writers run as the app role under the call's actor context, so row level security decides what they may write:

| What | Where | The policy that takes it |
|---|---|---|
| the mount, of kind `mount` with its source, active, at version 1, below its parent on its path | `nodes` | `nodes_actor`, over `cms_access_reaches(path)` |
| the exception of the mount for the entry, inserted or replaced hidden, or removed again, and the mount at the next version | `mount_overrides`, `nodes` | `mount_overrides_write`, `mount_overrides_change` and `mount_overrides_remove`, over `cms_access_node(mount_node_id)` |
| the changeset, its audit row with the mount's key, and its receipt | the changeset tables, `audit`, `receipts` |  |
| `mount.created` with the parent and the source, and `mount.override_set` with the entry and whether it is hidden | `events` |  |

Every context reads `mount_overrides`, the anonymous one included (`mount_overrides_read`), because a resolution must answer the exception to the public, and a row holds ids and a flag, no content; without an actor context the app role reads no row. The mount's source is read through the owner lookup `cms_structure_node`, and what the source shows of an entry through `cms_structure_mounted_entry`, past the actor's regions, because the source may lie outside them. The commit locks every node a command read through the owner lookup `cms_structure_lock_nodes` for the same reason: a source or an ancestor outside the regions is locked and checked at its version, instead of reading as absent and ending the command in `version_conflict`.

The invalidation subscriber receives `mount.override_set` and purges the `e-` key of the entry and the `n-` key of the mount node, hard when the entry is hidden and softly when it is shown again; every answer of a path through a mount depends on the mount's key as well as the source's (see [subscribers](subscribers.md)).

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the parent a mount is created below, or the mount an exception is set on, exists but the actor's grants do not reach it |
| `agent_visibility_forbidden` | an agent or a token issued `mount.set_override` to show an entry again that the source shows now or later (invariant 18) |
| `validation_failed` | the parent does not exist, is archived or is a mount; the source does not exist, is the mount itself, is a mount, is archived, or is the parent or above it; the node an exception is set on is not a mount or is archived; the entry does not exist; the mount hides the entry already, or does not hide it when the exception is taken off |
| `version_conflict` | the mount has an id that exists, or is at another version than the caller read; the parent, the source or an ancestor of the parent changed before the commit |

The [error reference](../reference/errors.md) explains each code.

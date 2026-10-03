---
title: Role commands
weight: 45
description: "The kernel's commands role.create and role.set_permissions: what a role is, the escalation guard on a role's content, step-up for a role that becomes administrative, grant.changed for every holder, and the rejections."
---

# Role commands

<!-- extension-point: Cbox\Cms\Core\Access\Domain\Commands\CreateRole -->
<!-- extension-point: Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions -->
<!-- extension-point: Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole -->

A role is a set of permissions with a classification ceiling (PRD 5.10, 12.2): the command and query names its holders may run, and the highest classification they read through it. A [grant](grant-commands.md) gives a role to an actor on a node. Two kernel commands, each version 1, change roles through the [command pipeline](commands.md) like every other write:

| Command | What it does | Surfaces |
|---|---|---|
| `role.create` | creates a role with its handle, ceiling and permissions | REST, the panel (Inertia) and the CLI (`cms:run`) |
| `role.set_permissions` | replaces a role's permissions with a full new list | REST, the panel (Inertia) and the CLI (`cms:run`) |

Neither is on MCP: an agent does not change who may do what. The commands and the mutations are `#[Experimental]`.

<!-- example: examples/Unit/Access/RoleCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutations\RolePermissionsSet;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;

// An administrator creates the role "night_desk" that may create and revise entries and read
// paths, with role.create. The caller makes the role's id. Later role.set_permissions gives the
// role the full new list, at the version the caller read. The kernel holds each permission the
// change adds to the escalation guard on every node where the role is granted.

it('creates a role with its permissions, and replaces them at the version read', function (): void {
    $role = RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000911');

    $create = new CreateRole(
        $role,
        new RoleHandle('night_desk'),
        ClassificationAccess::Internal,
        [new CommandName('entry.create'), new CommandName('entry.revise'), new CommandName('path.resolve')],
    );
    $set = new SetRolePermissions($role, new AggregateVersion(1), [new CommandName('entry.revise'), new CommandName('path.resolve')]);

    expect($create->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($create->handle->value)->toBe('night_desk')
        ->and($set->expectedVersions()->reads[0]->version?->value)->toBe(1)
        ->and($set->permissions)->toHaveCount(2);
});

it('keeps a role handle to lowercase letters, digits and underscores, and a role\'s permissions to one of each', function (): void {
    $role = RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000911');

    expect(static fn (): RoleHandle => new RoleHandle('Night desk'))->toThrow(InvalidIdentity::class)
        ->and(static fn (): RolePermissionsSet => new RolePermissionsSet($role, [new CommandName('entry.create'), new CommandName('entry.create')]))
        ->toThrow(InvalidMutation::class);
});
```

## role.create

`Cbox\Cms\Core\Access\Domain\Commands\CreateRole` takes:

- the `RoleId` of the new role, made by the caller, so a repeat with the same idempotency key is the same content. The command expects the role not to exist: an id a role has is `version_conflict`.
- the `RoleHandle`: a lowercase letter followed by up to 62 lowercase letters, digits and underscores, such as `night_desk`. A handle names one role: a handle another role has is refused with `validation_failed`, and two creates of one handle at once commit one after the other, the second with `version_conflict`.
- the `ClassificationAccess` ceiling, the highest classification the role's holders read through it.
- the permissions, each a `CommandName` once: the name of a command or a query of the registry, without its version, as `cms:actions` lists them. A name the registry does not know, or one named twice, is refused with `validation_failed` at its place in the list, such as `permissions[1]`. The list may be empty.

The new role is granted to nobody. It gets holders through `grant.assign`, which holds the issuing actor to every permission of the role on the grant's node.

## role.set_permissions

`Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions` takes the `RoleId`, the `AggregateVersion` of the role the caller read, and the role's new permissions in full, with the same rules as role.create's. A role at another version, or one that does not exist, is `version_conflict`. A list equal to the role's, in any order, changes nothing and is `validation_failed`.

The action reads the role with its permissions and every grant of the role that has not ended, whoever holds it and wherever it is, also outside the issuing actor's regions, because the change reaches every holder. The kernel checks each at commit under its lock: a grant of the role given, ended or changed after the action read them is `version_conflict`.

## No escalation

A change of a role's content requires that the issuing actor itself holds each added permission on the nodes the change touches (PRD 5.10, invariant 31). The kernel's authorize step holds both commands to that:

1. The issuing actor needs `role.create`, or `role.set_permissions`, on some node. Without it the command is `unauthorized`.
2. A new role's ceiling may not be above the issuing actor's classification access.
3. For each permission role.set_permissions adds, the issuing actor must itself hold it on every node where an allow of the role has not ended and on every node below it, in each of that grant's locales, or in every locale for a grant without a locale set, through a role of its own whose nearest grant above the node, or on it, allows. A node the issuing actor does not reach is one where it holds nothing.

A command that breaks 2 or 3 is refused with `grant_escalation_refused`. Taking permissions away is not held to 3, and neither is a deny of the role, which gives nothing. An actor that acts on behalf of a person is held to the rules with its own grants and with the person's.

Rule 3 is decided from the grants the issuing actor, and each actor it acts on behalf of, holds when the command is authorized. The commit holds each one's set of grants, `actor_grants:<actor>`, to the version the guard read, so a change of those grants that commits meanwhile makes the command `version_conflict`. role.set_permissions reads the set of every holder of the role too, because it changes what each holds.

## Step-up for a role that becomes administrative

A role is administrative when one of its permissions is a command that changes roles, grants, the identity mapping, connections or who is active; queries such as `role.list` and `grant.list` do not count (see [grant commands](grant-commands.md)). A change that makes a granted role administrative needs step-up, which is not built yet, so it is refused with `step_up_required`, after rules 1 to 3. A role that is granted nowhere may become administrative; a grant of it then needs step-up.

## What a command writes

The plan of `role.create` is one `RoleCreated` mutation, and of `role.set_permissions` one `RolePermissionsSet` followed by a `GrantRoleContentChanged` for every grant of the role that has not ended (see [plans](plans.md)). The app role writes no role and no grant itself: the writers call owner functions that run only in the transaction of a changeset of their own command by the actor of the context. In the command's one transaction the commit writes:

| What | Where |
|---|---|
| the role at version 1 with its permissions, or at its next version with the new list | `roles`, `role_permissions` |
| each grant of the role at its next version, with nothing else changed | `grants` |
| the changeset, its audit row with the keys of the role and the grants, and its receipt | the changeset tables, `audit`, `receipts` |
| `grant.changed` for each grant of the role: the holder, the role and the node, ids alone | `events` |

A new role has no holder, so role.create writes no event. Whatever caches an actor's compiled access drops it on `grant.changed`, so every holder of a changed role gets its new permissions from its next command or read.

## The access bootstrap's command

`access.bootstrap`, version 1, is the command `GrantBootstrapRole` of the one-time [access bootstrap](../developers/maintenance-commands.md#the-access-bootstrap). It carries a `role.create` and a `grant.assign` of the new role to a staff actor on a node, allowing in every locale, and its write action, `GrantBootstrapRoleAction`, composes the two plans into one: the role's creation, when no role has its id, then the grant. The role and its grant are therefore one changeset (GUARDRAILS 2.1), so a grant that cannot commit leaves no role behind. A role with the id is used as it is when it has the command's ceiling and every one of its permissions, and refused with `validation_failed` otherwise. It is on no surface: only `cms:access:bootstrap` runs it, as the installation operator in the maintenance process. Its writes run through the same owner functions as `role.create` and `grant.assign`, which accept a changeset of `access.bootstrap` too.

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the issuing actor may not run the command on any node |
| `grant_escalation_refused` | a new role's ceiling is above the issuing actor's classification access, or the issuing actor lacks an added permission on a node where the role is granted or below it |
| `step_up_required` | the change makes a granted role administrative |
| `validation_failed` | a name the registry does not know or a name given twice, a handle another role has, or a list equal to the role's |
| `version_conflict` | the role's id exists, the role is at another version or does not exist, or something read changed before the commit |

The [error reference](../reference/errors.md) explains each code.

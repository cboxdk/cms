---
title: Grant commands
weight: 44
description: "The kernel's commands grant.assign and grant.revoke: what a grant is, the escalation guard that keeps an actor from giving more than it holds, step-up for administrative roles, the event grant.changed and the rejections."
---

# Grant commands

<!-- extension-point: Cbox\Cms\Core\Access\Domain\Commands\AssignGrant -->
<!-- extension-point: Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant -->

A grant gives an actor a role on a node and the subtree below it (PRD 5.10): it allows or denies the role's permissions there, in a set of locales or in every locale. Two kernel commands, each version 1, change grants through the [command pipeline](commands.md) like every other write:

| Command | What it does | Surfaces |
|---|---|---|
| `grant.assign` | gives an actor a role on a node, allowing or denying | REST, the panel (Inertia) and the CLI (`cms:run`) |
| `grant.revoke` | ends a grant, as a deactivation ends an actor's grants | REST, the panel (Inertia) and the CLI (`cms:run`) |

Neither is on MCP: an agent does not change who may do what. The commands, the event and the mutations are `#[Experimental]`.

<!-- example: examples/Unit/Access/GrantCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Events\GrantChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChangedV1;

// An editor-in-chief gives a reporter the role "desk" on the news section, in Danish only, with
// grant.assign. The caller makes the grant's id. The kernel checks that the editor-in-chief holds
// grant.assign on the section and every permission of the role there in Danish, or refuses the
// grant with grant_escalation_refused. Later grant.revoke ends it, at the version the caller read.

it('grants a role on a node in some locales, and revokes it at the version read', function (): void {
    $grant = GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');

    $assign = new AssignGrant(
        $grant,
        ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902'),
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
        GrantEffect::Allow,
        [new Locale('da')],
    );
    $revoke = new RevokeGrant($grant, new AggregateVersion(1));

    expect($assign->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($assign->locales)->toEqual([new Locale('da')])
        ->and($revoke->expectedVersions()->reads[0]->version?->value)->toBe(1);
});

it('tells about a grant given or ended with ids alone', function (): void {
    $grant = GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902');

    $event = new GrantChanged($grant, 2, new GrantChangedV1(
        $actor,
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
    ));
    $data = $event->payload()->data();

    expect(GrantChanged::type()->name)->toBe('grant.changed')
        ->and($event->aggregate()->id->toString())->toBe($grant->toString())
        ->and($event->aggregate()->version)->toBe(2)
        ->and($data->get('actor')->kind)->toBe(DatumKind::Identifier)
        ->and($data->get('actor')->asIdentifier()->value)->toBe($actor->toString());
});

it('refuses an escalation and a grant that needs step-up as the actor\'s rights, on every surface', function (): void {
    $escalation = ErrorCode::GrantEscalationRefused->entry();
    $stepUp = ErrorCode::StepUpRequired->entry();

    expect($escalation->http)->toBe(HttpStatus::Forbidden)
        ->and($escalation->exit)->toBe(ExitCode::NoPerm)
        ->and($escalation->retryable)->toBeFalse()
        ->and($stepUp->http)->toBe(HttpStatus::Forbidden)
        ->and($stepUp->exit)->toBe(ExitCode::NoPerm);
});
```

## grant.assign

`Cbox\Cms\Core\Access\Domain\Commands\AssignGrant` takes:

- the `GrantId` of the new grant, made by the caller, so a repeat with the same idempotency key is the same content. The command expects the grant not to exist: an id a grant has is `version_conflict`.
- the `ActorId` of the actor to get the grant. Only an active staff or service actor gets one: an end user, or an actor that is pending, deactivated or deprovisioned, is refused with `validation_failed`.
- the `RoleId` of the role, and the `NodeId` of the node.
- the `GrantEffect`: `allow` gives the role's permissions on the node and below, `deny` keeps them out. The grant nearest above a node decides, and a deny wins over an allow on the same node.
- the locales, each a `Locale` once, or null for every locale. A grant limited to some locales never reaches content all locales share.

An actor holds a role on a node once: a second grant of the same role on the same node, while the first has not ended, is refused with `validation_failed`; revoke the first to give it another effect or other locales. The action reads the grant's id, the actor through the `ActorDirectory`, the role and the grant's slot (the actor, the role and the node), and the kernel checks each at commit under its lock, so a role changed, an actor deactivated or a second grant of the slot meanwhile ends in `version_conflict`.

## grant.revoke

`Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant` takes the `GrantId` and the `AggregateVersion` of the grant the caller read. A grant at another version, or one that does not exist, is `version_conflict`, and so is a grant on a node the issuing actor's regions do not reach: an actor learns nothing of the grants outside its part of the tree. A grant that has ended is refused with `validation_failed`. The grant ends as a deactivation ends an actor's grants: from the commit on it gives nothing, and it stays, ended by the changeset, so the access report can show it.

## No escalation

An actor can only give the roles it holds itself, on the nodes where it holds them (PRD 5.10, invariant 31). The kernel's authorize step holds both commands to that:

1. The issuing actor needs `grant.assign`, or `grant.revoke`, on the grant's node in each of its locales, or in every locale. Without it the command is `unauthorized`.
2. For an allow, the issuing actor must itself hold every permission of the role on the node and on every node below it, because the grant gives the role on the node's whole subtree, in each of the grant's locales: through a role of its own whose permissions name it and whose nearest grant above the node, or on it, allows. Holding it on a sibling node, or only in another locale, is not enough, and neither is holding it on the node with a deny of its own below it that no deeper grant allows again.
3. Its classification access on the node and on every node below it must not be below the role's ceiling: in each locale, the highest ceiling among its roles that reach the node, capped by its credential's ceiling.

A grant that breaks 2 or 3 is refused with `grant_escalation_refused`. A deny takes rights away and is not held to 2 and 3. Ending a deny gives back what it kept out, so `grant.revoke` of a deny is held to them as a grant of the role would be; ending an allow is not. An actor that acts on behalf of a person is held to the rules with its own grants and with the person's.

The rules are decided from the grants the issuing actor, and each actor it acts on behalf of, holds when the command is authorized, and those grants are part of what the command read. Each actor's set of grants is an aggregate, `actor_grants:<actor>`, whose version moves with every grant given to the actor, every revocation, every change of a held role's permissions and every deactivation. The commit locks the sets the guard decided from, and every command that changes an actor's grants reads and locks that actor's set too, so a revocation, a deny or a change of a held role that commits after the guard decided and before the command commits makes the command `version_conflict`, never a grant its issuer no longer may give. Read the grants again and retry.

## Step-up for administrative roles

A role is administrative when one of its permissions is a command, a write, that changes roles, grants, the identity mapping or connections, or who is active: a command of the registry in the `grant`, `role` or `identity` namespace, or `actor.activate`, `actor.deactivate` or `actor.reactivate`. Whoever holds it can change who may do what. A query changes nothing, so a role with only `role.list` and `grant.list`, such as an access reviewer's, is not administrative and is granted under rules 1 to 3 alone. PRD 5.16 requires step-up, a fresh authentication of a person in an interactive session, for a grant of one. Step-up is not built yet, so such a grant, and the end of a deny of one, is refused with `step_up_required` on every surface, after rules 1 to 3. The first administrator of an installation gets the role from the one-time access bootstrap in the maintenance process, which does not come through this guard.

## What a command writes

The plan of `grant.assign` is one `GrantAssigned` mutation, and of `grant.revoke` one `GrantRevoked` (see [plans](plans.md)). The app role writes no grant itself: the writers call owner functions that run only in the transaction of a changeset of their own command by the actor of the context. In the command's one transaction the commit writes:

| What | Where |
|---|---|
| the grant at version 1, or the end of it at its next version, with the changeset | `grants`, `grants.ended_changeset_id` |
| the changeset, its audit row with the grant's key, and its receipt | the changeset tables, `audit`, `receipts` |
| `grant.changed`: the actor, the role and the node, ids alone | `events` |

Whatever caches an actor's compiled access, or the credentials that act for it, drops it on `grant.changed`. A change of a role's permissions gives `grant.changed` for every grant of the role too (see [role commands](role-commands.md)). The next command or read of the actor compiles its access from its grants as they are.

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the issuing actor may not run the command on the grant's node in its locales |
| `grant_escalation_refused` | the issuing actor lacks a permission of the role on the node or below it in the grant's locales, or its classification access there is below the role's ceiling |
| `step_up_required` | the role is administrative |
| `validation_failed` | the actor may not get a grant, the role does not exist, the locales name one twice, the actor holds the role on the node already, or the grant has ended |
| `version_conflict` | the grant's id exists, the grant is at another version or outside the issuing actor's regions, or something read changed before the commit |

The [error reference](../reference/errors.md) explains each code.

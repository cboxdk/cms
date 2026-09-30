---
title: Actor commands
weight: 44
description: "The kernel's command actor.deactivate: what it changes in one changeset, the event actor.deactivated, and how it stops every command and read of the actor, one in flight included."
---

# Actor commands

<!-- extension-point: Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor -->

An actor is deactivated with the kernel's command `actor.deactivate`, version 1 (PRD 5.16, 6.4). It runs through the [command pipeline](commands.md) like every other write. It is exposed on no surface yet: the kernel's own issuers, such as the scheduler, call it, and the panel and the identity provider's signals that deactivate an actor come with later blocks. The command, its event and `DeactivationSource` are `#[Experimental]`.

## The command

`Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor` takes the `ActorId` of the actor and the `Cbox\Cms\Contracts\Identity\DeactivationSource` that deactivated it: `local` for a local command, such as an administrator's, which is the default, or `inactivity` for the automatic rule for staff who have not logged in within the login policy's period. The source is noted on the actor, because the rules for reactivating an actor depend on it.

<!-- example: examples/Unit/Identity/ActorCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivatedV1;

// An administrator's command, or the scheduler's inactivity rule, deactivates an actor with the
// kernel's actor.deactivate. The event tells a subscriber the actor, the source, the credential
// generation below which every credential is refused, and how many direct grants ended.

it('deactivates an actor and notes what deactivated it', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $byAdministrator = new DeactivateActor($actor);
    $byRule = new DeactivateActor($actor, DeactivationSource::Inactivity);

    expect($byAdministrator->source)->toBe(DeactivationSource::Local)
        ->and($byRule->source->value)->toBe('inactivity');
});

it('tells about the deactivation with ids, the source and counts', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $event = new ActorDeactivated(2, new ActorDeactivatedV1($actor, DeactivationSource::Local, credentialGeneration: 2, grantsEnded: 3));
    $data = $event->payload()->data();

    expect(ActorDeactivated::type()->name)->toBe('actor.deactivated')
        ->and($event->aggregate()->id->toString())->toBe($actor->toString())
        ->and($event->aggregate()->version)->toBe(2)
        ->and($data->get('source')->kind)->toBe(DatumKind::Enum)
        ->and($data->get('source')->asEnumValue())->toBe('local')
        ->and($data->get('grants_ended')->asInteger())->toBe(3);
});
```

## What one changeset does

The action reads the actor through the `ActorDirectory`, and the kernel reads the calling actor too. When the actor is active or pending, the plan is one `ActorDeactivated` mutation, and the commit writes, in the command's one transaction:

| What | Where |
|---|---|
| the state `deactivated`, the next version, the credential generation one higher and the source | `actors` |
| every direct grant of the actor that has not ended ends with the changeset; an ended grant gives nothing, and it stays so the grants can be shown and granted again | `grants.ended_changeset_id` |
| the changeset, its audit row and its receipt | the changeset tables, `audit`, `receipts` |
| `actor.deactivated`, about the actor at its new version | `events` |

The kernel keeps no compiled list of access regions: it compiles an actor's grants that have not ended into regions for each command and read (PRD 5.10), so a deactivated actor reaches nothing. The generation that rose refuses every credential the actor holds at once. The statements do not grow with the number of grants. The event carries the actor, the source, the new credential generation and the number of grants that ended, never text (see [events](events.md)).

## What it stops

From the commit on (invariant 37):

- a command as the actor, or on behalf of it, is rejected with `actor_not_active`, because the kernel reads the actor and its on-behalf-of chain as aggregates in every command;
- a command of the actor that was running when the deactivation committed fails with `version_conflict`: its commit locks the actor's row and finds it at a newer version;
- a read with the actor's credential is rejected with `actor_not_active` before any actor context is set.

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the caller may not deactivate actors |
| `validation_failed` | the actor does not exist or is deactivated or deprovisioned already: the command changes nothing, and nothing is committed |
| `version_conflict` | the actor, or the calling actor, changed after the command read it |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

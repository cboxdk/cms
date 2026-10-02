---
title: Actor commands
weight: 44
description: "The kernel's commands actor.register, actor.activate and actor.deactivate: the order of a registration, the actor's profile, the events, and how a deactivation stops every command and read of the actor, one in flight included."
---

# Actor commands

<!-- extension-point: Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor -->
<!-- extension-point: Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor -->
<!-- extension-point: Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor -->

An actor's life is a set of kernel commands (PRD 5.16, 6.4), each version 1, each through the [command pipeline](commands.md) like every other write:

| Command | What it does | Surfaces |
|---|---|---|
| `actor.register` | creates the actor pending, with its profile | the CLI (`cms:run`) |
| `actor.activate` | makes a pending actor active | REST, the panel (Inertia) and the CLI |
| `actor.deactivate` | deactivates an active or pending actor, ends its grants and refuses its credentials | none yet |

A registration has a fixed order (PRD 5.16): `actor.register` creates the actor `pending`, then the actor's credential is written, then `actor.activate` makes it `active`. A pending actor runs nothing: the kernel rejects a command as it, or on its behalf, with `actor_not_active`. The commands, their events and the identity values are `#[Experimental]`.

`actor.register` is on the CLI alone for now. A registration on REST or in the panel would leave a pending actor behind whenever the steps after it fail, and the job that deprovisions actors pending for 24 hours comes with a later block; the panel's own registration comes with the local login. The deactivation's surfaces, the panel and the identity provider's signals, come with later blocks too.

<!-- example: examples/Unit/Identity/ActorCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivatedV1;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegistered;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegisteredV1;

// A registration is actor.register, which creates the actor pending with its profile, and then,
// once its credential is written, actor.activate. The profile is personal data: the event of the
// registration carries ids and the class, never the name or the email. An administrator's
// command, or the scheduler's inactivity rule, deactivates an actor with actor.deactivate. Its
// event tells a subscriber the actor, the source, the credential generation below which every
// credential is refused, and how many direct grants ended.

it('registers a service actor with the staff actor responsible for it, and activates it at the version read', function (): void {
    $service = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000202');
    $responsible = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $register = new RegisterActor($service, ActorClass::Service, new DisplayName('Nightly import'), new EmailAddress('ops@example.com'), $responsible);
    $activate = new ActivateActor($service, new AggregateVersion(1));

    expect($register->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($activate->expectedVersions()->reads[0]->version?->value)->toBe(1);
});

it('refuses a display name or an email that breaks its form, without repeating it', function (): void {
    expect(static fn (): DisplayName => new DisplayName(' Mette'))->toThrow(InvalidIdentity::class, 'A display name is 1 to 200 characters')
        ->and(static fn (): EmailAddress => new EmailAddress('mette at example'))->toThrow(InvalidIdentity::class, 'An email address is a local part');
});

it('tells about the registration with ids and the class, never the profile', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000203');

    $event = new ActorRegistered(1, new ActorRegisteredV1($actor, ActorClass::Staff, null, credentialGeneration: 1));
    $data = $event->payload()->data();

    expect(ActorRegistered::type()->name)->toBe('actor.registered')
        ->and($data->get('class')->asEnumValue())->toBe('staff')
        ->and($data->get('responsible')->kind)->toBe(DatumKind::Null);
});

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

## actor.register

`Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor` takes:

- the `ActorId` of the actor, made by the caller, so a repeat with the same idempotency key is the same content. The command expects the actor not to exist: an id an actor has is `version_conflict`.
- its `ActorClass`, which never changes: `staff` for a person, `service` for an agent, an integration or a sidecar. `end_user` is refused with `validation_failed` until the end users' connections and login policy come.
- the profile: a `Cbox\Cms\Contracts\Identity\DisplayName`, 1 to 200 characters without control characters that start and end with a character that is not white space, and a `Cbox\Cms\Contracts\Identity\EmailAddress`, a local part, an `@` and a domain with a dot, at most 254 characters. The email is never the key of an identity.
- for a service actor, the `ActorId` of the person responsible for it, which must be an active staff actor (PRD 5.16: every service actor has a named responsible person). A staff actor has none.

The action reads the actor, which it expects absent, and the responsible person through the `ActorDirectory`; the kernel checks both at commit at the versions read, so a deactivation of the responsible person and the registration commit one after the other. The plan is one `ActorRegistered` mutation, and the commit writes, in the command's one transaction:

| What | Where |
|---|---|
| the actor, `pending` at version 1 and credential generation 1, with its class and the responsible person | `actors`, `actors.responsible_actor_id` |
| the profile, the display name and the email at profile version 1 | `actor_profiles` |
| the changeset, its audit row and its receipt | the changeset tables, `audit`, `receipts` |
| `actor.registered`: the actor, its class, the responsible person (or null) and the credential generation | `events` |

## The profile is personal data

The display name and the email are classified `personal` (PRD 12.2, `ActorProfile::CLASSIFICATION`). They live in `actor_profiles`, apart from the actor's state, so the actor directory, the events, the audit and the changeset never carry them, and no error message repeats them. `actor_profiles` has forced row level security, and the app role may only read it:

- an actor reads its own profile;
- an actor whose grants that have not ended give it a role whose permissions name `actor.list` reads the profiles of staff actors, when its classification access allows personal data;
- without an actor context no row is read.

A hook sees the plan through a view filtered to its classification access (see [hooks](hooks.md)). `ActorRegistered` implements `ClassifiedMutation` (see [plans](plans.md)), so a hook whose access does not allow personal data sees the registration without the profile.

## actor.activate

`Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor` takes the actor's id and the `AggregateVersion` of it the caller read; an actor at another version, or one that does not exist, is `version_conflict`. Only a pending actor is activated: an actor in any other state is refused with `validation_failed`, so a deactivated or deprovisioned actor never comes back through it. The plan is one `ActorActivated` mutation; the commit makes the actor `active` at its next version and writes the changeset, its audit row, its receipt and `actor.activated`, which carries the actor's id.

## actor.deactivate

`Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor` takes the `ActorId` of the actor and the `Cbox\Cms\Contracts\Identity\DeactivationSource` that deactivated it: `local` for a local command, such as an administrator's, which is the default, or `inactivity` for the automatic rule for staff who have not logged in within the login policy's period. The source is noted on the actor, because the rules for reactivating an actor depend on it.

The action reads the actor through the `ActorDirectory`, and the kernel reads the calling actor too. When the actor is active or pending, the plan is one `ActorDeactivated` mutation, and the commit writes, in the command's one transaction:

| What | Where |
|---|---|
| the state `deactivated`, the next version, the credential generation one higher and the source | `actors` |
| every direct grant of the actor that has not ended ends with the changeset; an ended grant gives nothing, and it stays so the grants can be shown and granted again | `grants.ended_changeset_id` |
| the changeset, its audit row and its receipt | the changeset tables, `audit`, `receipts` |
| `actor.deactivated`, about the actor at its new version | `events` |

The kernel keeps no compiled list of access regions: it compiles an actor's grants that have not ended into regions for each command and read (PRD 5.10), so a deactivated actor reaches nothing. The generation that rose refuses every credential the actor holds at once. The statements do not grow with the number of grants. The event carries the actor, the source, the new credential generation and the number of grants that ended, never text (see [events](events.md)).

From the commit on (invariant 37):

- a command as the actor, or on behalf of it, is rejected with `actor_not_active`, because the kernel reads the actor and its on-behalf-of chain as aggregates in every command;
- a command of the actor that was running when the deactivation committed fails with `version_conflict`: its commit locks the actor's row and finds it at a newer version;
- a read with the actor's credential is rejected with `actor_not_active` before any actor context is set.

## How they write

The app role writes no identity row itself. Each writer calls a function that runs as the owner role, and only in the transaction of a changeset of its command by the context's actor: `cms_identity_register_actor`, `cms_identity_activate_actor` and `cms_identity_deactivate_actor`. Anything else raises and writes nothing, so a direct insert into `actors` or `actor_profiles` by the app role fails with SQLSTATE 42501.

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the caller may not run the command |
| `validation_failed` | `actor.register`: an end user, a staff actor with a responsible person, or a service actor without an active staff actor responsible for it. `actor.activate`: the actor is not pending. `actor.deactivate`: the actor does not exist or is deactivated or deprovisioned already, so the command changes nothing |
| `version_conflict` | the actor exists already (`actor.register`), is at another version than the caller read (`actor.activate`), or the actor, the responsible person or the calling actor changed after the command read it |
| `json_invalid` | the document breaks a rule of the command's [JSON Schema](command-json.md), such as a display name with a line break |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

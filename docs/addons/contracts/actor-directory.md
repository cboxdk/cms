---
title: Actor directory
weight: 36
description: "The ActorDirectory contract: read an actor as an aggregate with its class, state, version and credential generation, the Postgres directory, the testkit's FakeIdentity and seeders, and the shared suite ActorDirectoryContract."
---

# Actor directory

<!-- extension-point: Cbox\Cms\Contracts\Identity\ActorDirectory -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\IdentitySeeder -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\IdentityHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\ActorDirectoryContract -->

An actor is whoever runs a command or a read: a person, an agent, an integration or a service identity (PRD 5.16). The kernel owns the actor and its state. `Cbox\Cms\Contracts\Identity\ActorDirectory` reads an actor as an aggregate, so the command kernel can read the actor and every actor in its on-behalf-of chain in the resolve phase, reject one that is not active, and check their versions again at commit (invariant 37). How a transport credential becomes an actor is on the [credential verifier](credential-verifier.md) page.

## The contract

The directory has one method, `find(ActorId $id): ?Actor`. It returns `null` when no actor has the id. An actor is never removed, so an id that was found once is always found: a deprovisioned actor is found in that state. A directory reads the current state from the primary, never from a copy that can lag, so a deactivation that committed is seen by the next read. It never writes: actors change only through commands.

An `Actor` is a final readonly class with five parts:

| Part | Type | What it is |
|---|---|---|
| `id` | `ActorId` | a UUIDv7 from the `IdGenerator`, fixed when the actor is created |
| `class` | `ActorClass` | `Staff`, `EndUser` or `Service`; set when the actor is created and never changed |
| `state` | `ActorState` | `Pending`, `Active`, `Deactivated` or `Deprovisioned` (PRD 6.4); only `Active` is active |
| `version` | `int` | counts every change of the actor, from 1 |
| `credentialGeneration` | `CredentialGeneration` | from 1; deactivation, deprovisioning and a revocation count it up |

A service actor never logs in: it only holds service credentials. A person who is both a reader and a member of staff has two actors, one of each class. `ActorState::revokesCredentials()` is true for `Deactivated` and `Deprovisioned`, the states whose entry counts the generation up, so every credential the actor holds is refused at once. A reactivated actor keeps the higher generation, so its earlier credentials stay refused.

## The default: PostgresActorDirectory

`cbox-cms.contracts` binds `ActorDirectory` to `Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory` (`packages/core/config/cbox-cms.php`). It reads the table `actors` through the query builder on the default connection as the app role, always on the write PDO. The core's migration creates `actors`, `service_credentials` and `service_credential_delegations` with row level security, forced so it holds for the owner role too (PRD 4.2). Their read policy lets every role read, because identity is resolved before an actor context exists; only the owner role has a write policy, and the app role holds only `SELECT`, so the app role cannot change an actor. The core runs the shared suite against it in `packages/core/tests/Contract/PostgresActorDirectoryContractTest.php`.

## Seeders and the fake: FakeIdentity

Login, sessions, personal tokens and the commands that create actors and issue credentials come with the identity block. Until then a test gets its actors and credentials from a seeder, `Cbox\Cms\Testkit\Identity\IdentitySeeder`, and nothing else writes them:

- `addActor(ActorClass $class, ActorState $state = ActorState::Active)` creates an actor at version 1 and generation 1.
- `changeState(ActorId $id, ActorState $state)` moves the actor to the state and counts its version up. `Deactivated` and `Deprovisioned` also count its generation up. A deprovisioned actor never changes again, and the seeder throws `InvalidIdentity`.
- `revokeCredentials(ActorId $id)` counts the generation and the version up, as `actor.credentials_revoke` will.
- `issue(ServiceCredentialSpec $spec)` issues a service credential; it is on the [credential verifier](credential-verifier.md) page.

`Cbox\Cms\Testkit\Identity\FakeIdentity` is the fake of both identity contracts and its own seeder. It keeps actors and credentials in memory, makes ids with the `IdGenerator` it is given and reads the time from its `Clock`, a `FakeClock` by default. `directory()` and `verifier()` return the fake itself. `Cbox\Cms\Testkit\Identity\Adapter\PostgresIdentitySeeder` is the seeder for the core's tables: it writes as the owner role, through the same rules as the fake, so a test runs the core's adapters against real Postgres. This example reads actors from the fake. It is in the `Unit` suite:

<!-- example: examples/Unit/Identity/ActorDirectoryTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

// The actor directory on the testkit's fake. The seeder side of FakeIdentity creates and changes
// actors, as the identity commands will; the directory side reads them back as aggregates.

it('reads an actor with its class, state, version and credential generation', function (): void {
    $identity = new FakeIdentity;
    $editor = $identity->addActor(ActorClass::Staff);
    $directory = $identity->directory();

    $actor = $directory->find($editor->id);

    expect($directory)->toBeInstanceOf(ActorDirectory::class)
        ->and($actor?->class)->toBe(ActorClass::Staff)
        ->and($actor?->state)->toBe(ActorState::Active)
        ->and($actor?->version)->toBe(1)
        ->and($actor?->credentialGeneration->value)->toBe(1);
});

it('counts the version and the credential generation up when an actor is deactivated', function (): void {
    $identity = new FakeIdentity;
    $editor = $identity->addActor(ActorClass::Staff);

    $identity->changeState($editor->id, ActorState::Deactivated);
    $actor = $identity->directory()->find($editor->id);

    expect($actor?->isActive())->toBeFalse()
        ->and($actor?->version)->toBe(2)
        ->and($actor?->credentialGeneration->value)->toBe(2);
});
```

## Running the shared suite against a replacement

Every implementation runs the testkit's shared suite, the trait `Cbox\Cms\Testkit\Identity\ActorDirectoryContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `identity(Clock $clock): IdentityHarness`, which returns a harness for a new, empty directory.

`IdentityHarness` extends `IdentitySeeder` with `directory()` and `verifier()`, the directory and the verifier under test, which read what the seeder wrote. The fake is its own harness. For a directory on a database, the harness pairs a seeder that writes that database with the directory, as the core's `PostgresIdentity` harness does with `PostgresIdentitySeeder`. A harness for a decorator passes the seeder's calls on to the harness of the directory it wraps. The same harness serves the verifier's suite, `CredentialVerifierContract`, so one class can run both; the [credential verifier](credential-verifier.md) page has an example.

The cases cover every class and state, an unknown id, and that a change the seeder commits is read at once, with the version and the credential generation counted as PRD 5.16 and 6.4 say.

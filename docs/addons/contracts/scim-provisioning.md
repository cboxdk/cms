---
title: SCIM provisioning
weight: 46
description: "The ScimProvisioning contract: SCIM 2.0 users and groups of one connection, with create, replace, a patch of active, delete and group membership, the idempotency key of connection, resource, desired state and version, a token that reaches only its own connection, the refusals, the testkit's FakeScimProvisioning and the shared suite ScimProvisioningContract with its harness."
---

# SCIM provisioning

<!-- extension-point: Cbox\Cms\Contracts\Identity\Provisioning\ScimProvisioning -->
<!-- extension-point: Cbox\Cms\Testkit\Provisioning\ScimProvisioningHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Provisioning\ScimProvisioningContract -->

An identity provider keeps the CMS's staff in step with its directory through SCIM 2.0 (RFC 7643 and RFC 7644): it creates, replaces and deactivates users, and maintains groups whose memberships become grants through the group-to-role mapping. `Cbox\Cms\Contracts\Identity\Provisioning\ScimProvisioning` is what the SCIM server calls for one connection (PRD 5.16, "Signaler fra IdP'en"). The contract is `#[Experimental]`; the identity module's SCIM server comes with B6.

## The contract

Every method takes the `ConnectionId` of the token that authenticated the call first. Each connection has its own token, which reaches the connection's users and groups and nothing else: a resource of another connection, or a local account, is `scim_resource_not_found`, and a member that is not a user of the connection is `scim_invalid_value`. Issuing, showing, hashing and rotating the tokens is not part of this contract.

| Method | SCIM | Change |
|---|---|---|
| `createUser($connection, ScimUser)` | `POST /Users` | `Created` |
| `replaceUser($connection, $id, ScimUser, ?$ifMatch)` | `PUT /Users/{id}` | `Replaced`, `Deactivated`, `Reactivated` |
| `setUserActive($connection, $id, bool, ?$ifMatch)` | `PATCH /Users/{id}` of `active` | `Deactivated` or `Reactivated` |
| `deleteUser($connection, $id, ?$ifMatch)` | `DELETE /Users/{id}` | `Deleted` (`actor.deprovision`) |
| `user($connection, $id)` | `GET /Users/{id}` | none |
| `createGroup($connection, ScimGroup)` | `POST /Groups` | `Created` |
| `replaceGroup($connection, $id, ScimGroup, ?$ifMatch)` | `PUT /Groups/{id}` | `Replaced`, `MembersChanged` |
| `changeMembers($connection, $id, MembershipChange, ?$ifMatch)` | `PATCH /Groups/{id}` of `members` | `MembersChanged` |
| `deleteGroup($connection, $id, ?$ifMatch)` | `DELETE /Groups/{id}` | `Deleted` |
| `group($connection, $id)` | `GET /Groups/{id}` | none |

A `ScimUser` is its `externalId`, a `Subject` that carries the same immutable value as the connection's declared claim of the ID token (`sub` by default), so a user created through SCIM is the IdP identity and the first login finds its actor; its `userName`, an optional display name and email address, and `active`. An email address is never a key. A `ScimGroup` is its `displayName`, an optional `externalId` and its members, each a `ScimResourceId` of a user, sorted and each once. The CMS gives each resource its `ScimResourceId` and a `ResourceVersion` from 1, one higher after each call or command that changes it; `etag()` writes it as a weak entity tag.

Each change is committed before the call returns, through the command its `ScimChange` names. A user or group outcome (`ScimUserOutcome`, `ScimGroupOutcome`) holds the resource as it is now, its changes in the order the commands ran and their `ScimIdempotencyKey`: the connection, the resource type and id, the hash of the desired state (`ScimUser::canonical()` or `ScimGroup::canonical()` of the state the call asks for, or `ScimIdempotencyKey::DELETED`) and the version before the change, null for a create. `unitOfWork()` is the unit of work of the command's internal envelope, so the pipeline replays a command whose key it committed. A call that asks for the state the resource has changes nothing and has no key: a repeated request has one effect, and deactivating a deactivated actor does nothing. A deactivation after a reactivation meets another version and is a new change. `ScimDeletion` holds the type, the id and the key; the resource then answers 404, and a repeated DELETE is refused.

The refusals are `ScimRefused` with a `ScimErrorCode`, whose `scimType()` gives the `scimType` of the SCIM error response:

| Reason | Code | Status | When |
|---|---|---|---|
| `ResourceNotFound` | `scim_resource_not_found` | 404 | no resource of the connection has the id |
| `Uniqueness` | `scim_uniqueness` | 409 | another resource of the connection has the `externalId` or `userName` (without regard to case), or the group's `displayName` (without regard to case); a create that repeats the existing state is not refused |
| `VersionMismatch` | `scim_version_mismatch` | 412 | `If-Match` names another version than the current one |
| `Mutability` | `scim_mutability` | 400 | a replace would change the `externalId` |
| `InvalidValue` | `scim_invalid_value` | 400 | a member is not a user of the connection |
| `ReactivationRefused` | `scim_reactivation_refused` | 409 | `active=true` for an actor another source deactivated, such as a security event, a local command or the inactivity rule |

Only the source that deactivated an actor reactivates it; any other reactivation is `actor.reactivate` with four eyes and step-up. The codes are in the [error reference](../../reference/errors.md).

## The fake: FakeScimProvisioning

`Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning` holds the users and groups of every connection, gives ids `user-<n>` and `group-<n>`, and records which source deactivated each user. It runs no command. It is its own harness. This example is in the `Unit` suite:

<!-- example: examples/Unit/Provisioning/ScimProvisioningTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimErrorCode;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimRefused;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserName;
use Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning;

// An identity provider provisions a person, then sends active=false twice, as it does when it did
// not get the first answer. The first PATCH deactivates the actor; the second asks for the state
// the user has, so it changes nothing. Another connection's token cannot see the user (PRD 5.16).

it('deactivates a provisioned user once and keeps it from other connections', function (): void {
    $scim = new FakeScimProvisioning;
    $acme = new ConnectionId('entra-acme');
    $ada = new ScimUser(new Subject('00u-ada'), new ScimUserName('ada@acme.example'), new DisplayName('Ada Lovelace'), null);

    $id = $scim->createUser($acme, $ada)->user->id;
    $first = $scim->setUserActive($acme, $id, false);
    $repeat = $scim->setUserActive($acme, $id, false);
    $refusal = null;

    try {
        $scim->user(new ConnectionId('okta-globex'), $id);
    } catch (ScimRefused $refused) {
        $refusal = $refused->reason;
    }

    expect($first->changes)->toBe([ScimChange::Deactivated])
        ->and($first->key?->unitOfWork()->value)->toStartWith('scim:')
        ->and($repeat->changed())->toBeFalse()
        ->and($repeat->user->version->value)->toBe(2)
        ->and($refusal)->toBe(ScimErrorCode::ResourceNotFound);
});
```

## Running the shared suite against a SCIM server

Every implementation runs the shared suite, the trait `Cbox\Cms\Testkit\Provisioning\ScimProvisioningContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `scim(): ScimProvisioningHarness`. The harness gives the provisioning under test, empty, with `provisioning()`, and deactivates a user through another source than SCIM with `deactivateElsewhere()`, as a harness for the real server does by running `actor.deactivate` with another source.

The cases cover creating, reading, replacing, patching `active` and deleting users and groups, a repeated create, deactivation and membership change, which have one effect, the parts of the key, a deactivation after a reactivation, a call for another connection's resource and a member of another connection, uniqueness, the immutable `externalId`, `If-Match`, and a reactivation refused for an actor another source deactivated. `packages/testkit/tests/Signals/PlantedReceiversTest.php` runs it against a provisioning that reaches another connection's resources and one that commits a repeated deactivation again, and each fails.

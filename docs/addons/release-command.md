---
title: Release command
weight: 43
description: "The kernel's command variant.release: what a caller sends, what the kernel checks and stores when a revision is released, the event it emits, and how it is rejected."
---

# Release command

<!-- extension-point: Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant -->

A release answers one question: which revision of a variant readers see (PRD 5.6). The kernel's command `variant.release`, version 1, makes a revision of an entry's shared variant its released revision. It runs through the [command pipeline](commands.md) like every other write, on every surface: `#[Action]` exposes it on REST, Inertia, MCP and the CLI. Where and when the entry is shown is decided by its placements, not by the release. The command works for any type from its schema, and it and its event are `#[Experimental]`.

## The command

`Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant` takes the entry, the `RevisionNumber` of the revision to release and the `AggregateVersion` of the shared variant the caller read. `variant()` gives the `VariantRef` it releases, and `expectedVersions()` that variant at the version given. Any revision of the variant can be released, the newest draft or an older one; releasing the revision that is released already changes nothing and is rejected as such.

<!-- example: examples/Unit/Entries/ReleaseCommandTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleasedV1;

// A surface, a job or the scheduler releases a revision with variant.release: the entry, the number
// of the revision to release and the version of the shared variant the caller read. The kernel
// tells subscribers with variant.released, which carries revision numbers and never a field's value.

it('releases a revision of the shared variant at the version the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $release = new ReleaseVariant($entry, new RevisionNumber(4), new AggregateVersion(6));

    expect($release->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($release->expectedVersions()->of($release->variant()))->toEqual(ReadVersion::at($release->variant(), new AggregateVersion(6)))
        ->and(ErrorCode::TypeNotReleasable->value)->toBe('type_not_releasable');
});

it('tells which revision was released, which published revision readers see and which they saw before', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');
    $variant = new VariantRef($entry, VariantKey::shared());

    $event = new VariantReleased(7, new VariantReleasedV1($entry, $variant, 4, 5, 2));
    $data = $event->payload()->data();

    expect(VariantReleased::type()->name)->toBe('variant.released')
        ->and($event->aggregate()->version)->toBe(7)
        ->and([$data->get('revision')->asInteger(), $data->get('published')->asInteger(), $data->get('previous')->asInteger()])->toBe([4, 5, 2]);
});
```

## What the kernel checks

Before it commits, the kernel checks, in this order:

1. The variant is at the version the caller read (invariant 11), or the call is `version_conflict`.
2. The caller is not an agent (invariant 18): a release changes what the public sees, so it is `agent_visibility_forbidden` for a credential issued to an agent, and for an envelope that records an agent as the issuer. The kernel judges the plan, so a composite command that releases as one of its steps is held to the same rule.
3. The type has revisions to release: its stages are `draft-release` and its history is `full`. A type with `stages: none` is public as soon as it is saved, and a type whose history is `audit-only` or `none` keeps no revision for the head to point at, so both are `type_not_releasable`.
4. The revision exists, and it validates against its own schema version at the release stage, where the fields required on release are required too (invariants 5 and 36). Its errors point below `revision`, such as `revision.headline`. A revision written under another schema version than the one whose rules the installation has cannot be checked, so it is rejected; save it again and release the new revision.

## What the kernel stores

The commit writes, in the command's one transaction, as the app role under the caller's actor context:

| What | Where |
|---|---|
| the published revision: a released draft is kept as a new revision of the kind `published`, numbered after the variant's highest number, with the draft's schema version and a copy of its content; a revision published before is released as it is | `revisions`, `revision_payloads` |
| the head of the shared variant: its published revision, the release state `released` and the variant's next version | `variant_heads` |
| the release: the published revision, the time it took effect and the changeset | `release_log` |
| the released row of the type's table, from the revision's fields; the draft row stays only where the pending draft differs from what was released | `<owner>__<handle>` |

The next `entry.revise` numbers its revision after the published one. `release_log` answers which revision was public at a given time. A withdrawn variant is released again only by reinstate (PRD 6.4, invariant 7).

A changeset of `variant.release` emits `variant.released` about the variant: the number of the revision the command released, the number of the published revision readers see now, and the number released before, or null for the variant's first release (see [events](events.md)).

## Rejections

| Code | When |
|---|---|
| `version_conflict` | the variant is at another version than the command gives, the entry does not exist or the caller cannot reach it, or another call changed the variant before the commit |
| `agent_visibility_forbidden` | the caller is an agent (invariant 18) |
| `type_not_releasable` | the entry's type has `stages: none`, or a history that keeps no revisions |
| `validation_failed` | the variant has no such revision, the revision breaks the type's rules at the release stage or was written under another schema version, or it is the released revision already |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

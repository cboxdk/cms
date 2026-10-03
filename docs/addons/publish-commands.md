---
title: Publish commands
weight: 46
description: "The kernel's composite commands entry.publish and entry.unpublish: the release and the home placement going live in one changeset, what a dry run shows, how unpublishing takes it all back, their events and how they are rejected."
---

# Publish commands

<!-- extension-point: Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry -->
<!-- extension-point: Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry -->

Publishing is one action for an editor (PRD 6.4): the panel's primary button releases the content and puts its home placement live, now or at a time. The kernel's command `entry.publish`, version 1, does both in one changeset, and `entry.unpublish`, version 1, takes both back. They run through the [command pipeline](commands.md) like every other write, on every surface: `#[Action]` exposes them on REST, Inertia, MCP and the CLI. The single commands [`variant.release`](release-command.md) and [`placement.set_window`](placement-commands.md) stay for the advanced cases. Both work for any type from its schema; the kernel names no type (GUARDRAILS 2.4). The commands and the event `variant.unreleased` are `#[Experimental]`.

## The commands

`Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry` takes the entry, the `AggregateVersion` of its shared variant the caller read, the `RevisionNumber` to release, the entry's home placement, the version of it the caller read, the `Locale` and an optional `TimeWindow`: without one the placement is live from now, and a window schedules it. The home placement is a placement of the entry below its home node. A type with `stages: none` is public as soon as it is saved, so it has no revision to release: its revision is null, and the command only puts the placement live.

`Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry` takes the entry and the version of its shared variant the caller read.

<!-- example: examples/Unit/Publishing/PublishCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;

// The panel's primary button publishes with entry.publish: the entry, the version of its shared
// variant, the revision to release, its home placement at the version read, the locale and, to
// schedule it, a window. entry.unpublish takes it back with the entry and its variant's version.

it('publishes a revision and the home placement now, or in a window', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000301');
    $home = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000351');

    $now = new PublishEntry($entry, new AggregateVersion(6), new RevisionNumber(4), $home, new AggregateVersion(2), new Locale('da'));
    $later = new PublishEntry($entry, new AggregateVersion(6), new RevisionNumber(4), $home, new AggregateVersion(2), new Locale('da'), new TimeWindow(new DateTimeImmutable('2026-03-11T06:00:00Z')));

    expect($now->window)->toBeNull()
        ->and($later->window?->from?->format('Y-m-d H:i'))->toBe('2026-03-11 06:00')
        ->and($now->expectedVersions()->of($home))->toEqual(ReadVersion::at($home, new AggregateVersion(2)))
        ->and($now->expectedVersions()->of($now->variant()))->toEqual(ReadVersion::at($now->variant(), new AggregateVersion(6)));
});

it('unpublishes the entry at the version of its shared variant the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000301');

    $unpublish = new UnpublishEntry($entry, new AggregateVersion(7));

    expect($unpublish->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($unpublish->expectedVersions()->of($unpublish->variant()))->toEqual(ReadVersion::at($unpublish->variant(), new AggregateVersion(7)));
});
```

## Composition

Each command is composite (PRD 6.2): its plan is made of the plans of the kernel's planners, and the whole is authorized, validated and committed as one changeset. No action calls another write action.

- `entry.publish` is the release planner's `VariantReleased` of the revision, followed by the placement planner's `PlacementWindowSet` of the home placement in the locale, with the moves of the canonical flag the window causes (invariant 14). A step that would change nothing is left out: the release of the revision that is released already, and a window when the placement is live already and the call asks for now, or has the window asked for. A call where both are left out changes nothing and is rejected.
- `entry.unpublish` is the release planner's `VariantUnreleased` of the revision released until now, followed by the placement planner's `PlacementClosed` of every placement of the entry that is visible now or later, in every locale and on every site. A closed placement is hidden and loses its window, so publishing the content again shows it only where a window is set again. Withdrawn placements stay withdrawn (invariant 7), and a hidden placement or one whose window has ended is left as it is. For a type with `stages: none` it only closes the placements.

Publishing and unpublishing content is decided on the entry's home (PRD 5.10). `entry.publish` needs the home and the home placement's node in the command's locale for the window, and, when its plan releases a revision, the home in every locale, as `variant.release` does: the release changes the shared variant that every locale and site serves, so a grant limited to some locales, or a deny in one, does not allow it. `entry.unpublish` therefore closes placements below nodes the caller's grants do not reach too; the kernel writes those through an owner function that runs only in the transaction of an `entry.unpublish` changeset. Unpublishing needs no legal basis and can be reversed: the entry can be published again. Withdrawal is something else, for legal takedowns only.

## The dry run

A dry run of `entry.publish` lists every placement that becomes visible in its `DryRunReport` (`visible`, a list of `BecomesVisible`): the placement, the locale and the time it becomes visible, the time the command read at for now and the window's start for later. Releasing the content also shows every placement of the entry whose window was open already, on every site, so they are listed too, although the plan does not change them. A placement that was visible before and stays visible from the same time is not listed. The action gives the list because it implements `ReportsVisibility` (see [commands](commands.md#the-write-action)).

## What one changeset writes

| What | Where |
|---|---|
| a publish: the published revision, the head, the release and the released row of the type's table, as `variant.release` writes them | `revisions`, `revision_payloads`, `variant_heads`, `release_log`, `<owner>__<handle>` |
| a publish: the home placement's window and state, and the canonical flag | `placement_locales`, `placements` |
| an unpublish: the head points at no published revision and is `unreleased`; the release log records the action `unreleased`; the type table's released row is removed and its values stay as the draft row where the variant had none | `variant_heads`, `release_log`, `<owner>__<handle>` |
| an unpublish: each closed placement is `hidden` without a window or a next transition | `placement_locales`, `placements` |
| the changeset, its audit row and its receipt | the changeset tables, `audit`, `receipts` |

The events are those of both sub-plans: `variant.released` and `placement.visibility_changed` for a publish, and `variant.unreleased` and one `placement.visibility_changed` per closed placement for an unpublish. `variant.unreleased` carries the entry, the variant and the number of the revision that was released, never a field's value (see [events](events.md)).

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the caller's grants do not reach the home placement's node in the command's locale, or the plan releases a revision and they do not reach the entry's home in every locale |
| `agent_visibility_forbidden` | an agent or a token publishes (invariant 18) |
| `type_not_releasable` | the entry's type has stages but a history that keeps no revisions |
| `validation_failed` | the placement is not a placement of the entry below its home node, it has no such locale or is withdrawn there, the revision is missing for a type with stages or given for one with `stages: none`, the revision breaks the type's rules at the release stage, or the call changes nothing |
| `version_conflict` | the variant or the placement is not at the version the caller read, the entry does not exist or the caller cannot reach its home, or something the command read changed before the commit |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

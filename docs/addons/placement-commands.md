---
title: Placement commands
weight: 45
description: "The kernel's commands placement.create and placement.set_window: where a placement is decided, the slug and canonical rules the kernel keeps, the visibility states a window gives, the events they emit, and how they are rejected."
---

# Placement commands

<!-- extension-point: Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement -->
<!-- extension-point: Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow -->

An entry is shown on a site through a placement: the entry below a node of the site, with a slug and a visibility window in each language (PRD 5.7). Two commands of the kernel, version 1 of each, make and open placements: `placement.create` and `placement.set_window`. They run through the [command pipeline](commands.md) like every other write, on every surface: `#[Action]` exposes them on REST, Inertia, MCP and the CLI. Both work for any type; the kernel names no type (GUARDRAILS 2.4). The commands, `LocaleSlug`, `Visibility` and the events are `#[Experimental]`.

## The commands

`Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement` places an entry: the `PlacementId`, which the caller makes, the `EntryId`, the `NodeId` it is placed below, the `SiteId` of the site the node belongs to, and a `LocaleSlug` for each language the placement is to have, each a `Locale` the site publishes in with its `Slug`. A slug is one segment of a path: 1 to 255 characters with no slash or white space, and not `.` or `..`. The placement is hidden in every language until its window is set. The command expects the placement not to exist.

`Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow` sets the window in which the placement is live in one language: the placement, the `AggregateVersion` of it the caller read, the `Locale` and a `TimeWindow` of `live_from` and `live_until`, either of them open. A null window hides the placement in that language.

<!-- example: examples/Unit/Placements/PlacementCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Visibility;

// A regional desk takes a story onto its site: it places the entry below a section of the site
// with a slug in each language the site publishes in, and then opens the placement's window. The
// placement is hidden until its window opens; the kernel keeps one canonical placement of the
// entry per language.

it('places an entry below a node of a site, hidden, with a slug per locale', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');

    $place = new CreatePlacement(
        $placement,
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
        [new LocaleSlug(new Locale('da'), new Slug('havnen-aabner')), new LocaleSlug(new Locale('en'), new Slug('harbour-opens'))],
    );

    expect($place->expectedVersions()->reads)->toEqual([ReadVersion::absent($placement)])
        ->and(PlacementCreated::type()->name)->toBe('placement.created');
});

it('opens the window of the placement in one locale at the version the caller read', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');
    $from = new DateTimeImmutable('2026-10-01T06:00:00Z');
    $window = new TimeWindow($from, $from->modify('+7 days'));

    $open = new SetPlacementWindow($placement, new AggregateVersion(1), new Locale('da'), $window);
    $hide = new SetPlacementWindow($placement, new AggregateVersion(2), new Locale('da'), null);

    expect($open->expectedVersions()->of($placement))->toEqual(ReadVersion::at($placement, new AggregateVersion(1)))
        ->and(new PlacementWindowSet($placement, new Locale('da'), $window)->makesPublic())->toBeTrue()
        ->and(new PlacementWindowSet($placement, new Locale('da'), $hide->window)->makesPublic())->toBeFalse()
        ->and(Visibility::of($window, $from->modify('-1 hour')))->toBe(Visibility::Scheduled)
        ->and(Visibility::nextTransition($window, $from->modify('-1 hour')))->toEqual($from)
        ->and(PlacementVisibilityChanged::type()->name)->toBe('placement.visibility_changed');
});
```

## Where a placement is decided

A placement is decided on the placement's node, not on the entry's home (PRD 5.10): the actor's grants must reach the node, whatever node the entry lives on, so a regional desk can take a national story onto its site and open and close it there without any right to the story's text. The entry must be one the actor can read: homed where its grants reach, or live somewhere. A placement below a node the actor's grants do not reach is refused with `unauthorized`, for `placement.set_window` too.

## What the kernel keeps

- **One placement per URL** (invariant 15). A slug is unique below a node in a language among the placements that are not withdrawn, so a URL resolves to one placement (PRD 5.9). A slug another placement has is refused with `placement_slug_taken`; two commands that claim the same slug at the same time commit one after the other, and the second is `version_conflict`.
- **One canonical placement per entry and language** (invariant 14). At most one placement of an entry is canonical in a language, and one is whenever any placement of it is visible; every other placement names it with `rel=canonical`. The kernel sets the flag, across every site, also on placements below nodes the caller cannot reach: a placement that stays visible keeps the flag, a placement that becomes visible takes it from one that is not, and while none is visible the flag stays where it is. A withdrawn placement is never canonical.
- **Visibility states** (PRD 6.4). The commit derives a state from the window at its own time: `hidden` without a window, `scheduled` before `live_from`, `live` inside the window and `expired` after `live_until`, with `next_transition_at`, the time the state next changes (PRD 6.7). `withdrawn` is set only by a withdrawal and left only by a reinstatement (invariant 7), so a withdrawn placement's window is refused. Until the scheduler of block B3, nothing moves a stored state when time passes a window's edge: a read decides visibility from the window at its own time.
- **People make things public** (invariant 18). A window, now or later, makes a placement public, which an agent or a token may not do: the call is refused with `agent_visibility_forbidden`. An agent can create a placement, which is hidden, and hide one.

## What one changeset writes

| What | Where |
|---|---|
| the placement at its version | `placements` |
| its released generation below the node | `placement_generations` |
| each language's slug, state, window, next transition and canonical flag | `placement_locales` |
| the changeset, its audit row and its receipt | the changeset tables, `audit`, `receipts` |
| `placement.created` about a new placement, and `placement.visibility_changed` about a placement whose window was set | `events` |

M1 has no placement workflow, so the commands write the released stage (PRD 4.1). A placement whose canonical flag moves is changed by the changeset too, at its next version. The events carry ids, the locale, the states before and after and the times, never a slug (see [events](events.md)).

## Rejections

| Code | When |
|---|---|
| `unauthorized` | the actor's grants do not reach the node, or the placement's node |
| `validation_failed` | the node is not below the site's root or is a mount, the site does not publish in a language, a language is given twice or none is, the entry is not one the actor can read, the placement has no such language, or it is withdrawn |
| `placement_slug_taken` | another placement that is not withdrawn has the slug below the node in the language |
| `agent_visibility_forbidden` | an agent or a token opens a window |
| `version_conflict` | the placement exists already, is not at the version the caller read, or it, a slug it claims or another placement of the entry in the language changed after the command read it |

Nothing of a rejected call is kept. See the [error catalog](errors.md) for each code on every surface.

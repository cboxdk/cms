---
title: Plans and mutations
weight: 43
description: "What a write action's plan holds: typed mutations with typed ids, the kernel-generic field values of a revision, and sub-plans from other planners."
---

# Plans and mutations

<!-- extension-point: Cbox\Cms\Contracts\Plans\Mutation -->
<!-- extension-point: Cbox\Cms\Contracts\Fields\FieldValue -->
<!-- extension-point: Cbox\Cms\Contracts\Plans\ChangesPublicVisibility -->

A write action's `plan()` returns a `Cbox\Cms\Contracts\Plans\Plan`: what the write will change, as an ordered list of typed mutations and sub-plans (GUARDRAILS 2.1, PRD 6.2). Nothing in a plan has been written; the kernel writes the mutations in the command's one transaction, after authorization, hooks and validation. All the types on this page are `#[Experimental]`.

## Composition

A plan composes the plans of other commands' planners, so one action can do several things atomically, such as a curation that also creates a placement. `new Plan(...)` takes mutations and plans; `then(...)` gives a new plan with more steps after the plan's own, and leaves the plan unchanged. `mutations()` gives every mutation in the order the kernel applies them: the steps in the order given, each sub-plan's mutations where the sub-plan stands, depth first. The whole is authorized, validated and committed as one changeset. An action never calls another write action; a flow of several independent changesets is an operation (see [operations](../developers/operations.md)).

## Mutations

A mutation implements `Cbox\Cms\Contracts\Plans\Mutation`: a final readonly class of typed ids and values, and `aggregate()`, the `AggregateRef` of the aggregate it changes. The kernel's mutations are in `Cbox\Cms\Contracts\Plans\Mutations`:

| Mutation | Aggregate | What changes |
|---|---|---|
| `EntryCreated(entry, type, home)` | the entry | an entry of the `TypeId` is created with its home `NodeId` |
| `RevisionCreated(entry, variant, revision, fields)` | the variant | a revision of the variant, with its `FieldValues` |
| `HeadMoved(entry, variant, from, to)` | the variant | the variant's head moves from a revision, or from none, to another revision |
| `VariantReleased(entry, type, variant, revision)` | the variant | the revision becomes the variant's released revision |
| `VariantUnreleased(entry, variant, revision)` | the variant | the variant has no released revision any more; `revision` is the one it had |
| `PlacementCreated(placement, entry, node, site)` | the placement | the entry is placed below the node of the site |
| `PlacementLocaleAdded(placement, locale, slug, canonical)` | the placement | the placement gets the `Locale` with its `Slug`, hidden, canonical as the kernel decides |
| `PlacementWindowSet(placement, locale, window)` | the placement | the `TimeWindow` in which the placement is live in the `Locale`; null hides it there |
| `PlacementCanonicalSet(placement, locale, canonical)` | the placement | the placement becomes, or stops being, the canonical placement of its entry in the `Locale` |
| `ActorDeactivated(actor, source)` | the actor | the actor is deactivated (PRD 5.16), by the `DeactivationSource` given, `local` by default |

A mutation that can make content public implements `Cbox\Cms\Contracts\Plans\ChangesPublicVisibility`, whose `makesPublic()` says whether it does, now or later, such as a `PlacementWindowSet` with a window, or a `VariantReleased`, which always does. The kernel refuses a plan with one that does from an agent or a token with `agent_visibility_forbidden` (invariant 18): the envelope's issuer is an agent, or the credential was issued for one.

The ids are value objects over a UUIDv7: `EntryId`, `NodeId`, `PlacementId`, `SiteId`, `ActorId` and `TypeId`. A variant is a `VariantKey`, `shared` or a `Locale` such as `en-GB`; a revision is a `RevisionNumber` from 1. The kernel knows no content type (GUARDRAILS 2.4): an entry's type is the `TypeId` of its blueprint.

## Field values

The fields of a revision are a `Cbox\Cms\Contracts\Fields\FieldValues`, the kernel's generic structure that holds the fields of any type without knowing the type. The owner's fields are a `FieldMap` of `NamedValue`s by `FieldHandle`, and each extender's fields are an `ExtensionFields` under its `FieldNamespace`, so an extender's field and the owner's field of the same handle never collide (PRD 11.12). Maps are sorted and hold each handle, key or namespace once, so the order the values were given in is not part of the value. An absent field is not in its map; a present field without a value holds `NullValue`.

Every value implements `Cbox\Cms\Contracts\Fields\FieldValue` and compares by value with `equals()`:

| Value | Holds |
|---|---|
| `NullValue` | no value |
| `TextValue` | UTF-8 text, such as a text, long text or select field |
| `IntegerValue` | a whole number |
| `DecimalValue` | an exact decimal as canonical text, so `012.50` is `12.5` |
| `BooleanValue` | true or false |
| `DateValue` | a calendar date as `YYYY-MM-DD` |
| `DateTimeValue` | an instant in UTC with microseconds |
| `ListValue` | an ordered list of values |
| `GroupValue` | the fields of a group field, as a `FieldMap` |
| `MapValue` | values by any string key, for structured content such as rich text |

The records generated from a blueprint convert to and from this structure, and the codecs give it its JSON form.

This example composes a plan from two sub-plans and reads the aggregate of each mutation. It is in the `Unit` suite:

<!-- example: examples/Unit/Pipeline/PlanTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Plan;

// A plan composes sub-plans from other planners; the kernel applies the mutations depth first, each
// sub-plan's where it stands, and every mutation names the aggregate it changes.

it('applies a composed plan in order', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000005');
    $window = new PlacementWindowSet($placement, new Locale('da'), new TimeWindow(new DateTimeImmutable('2026-10-01T06:00:00Z')));
    $deactivated = new ActorDeactivated(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000a'));

    $plan = new Plan($window)->then(new Plan($deactivated));

    expect(array_map(static fn (Mutation $mutation): string => $mutation->aggregate()->aggregateKey(), $plan->mutations()))->toBe([
        'placement:01936f5e-8a2b-7c3d-9e4f-000000000005',
        'actor:01936f5e-8a2b-7c3d-9e4f-00000000000a',
    ])
        ->and($plan->steps)->toHaveCount(2)
        ->and(Plan::empty()->isEmpty())->toBeTrue();
});
```

---
title: Hooks
weight: 37
description: "How an addon joins the command pipeline: authorize, transform and validate hooks, the classification-filtered view of the plan they get, what the kernel refuses, and their time budgets."
---

# Hooks

<!-- extension-point: Cbox\Cms\Contracts\Hooks\AuthorizeHook -->
<!-- extension-point: Cbox\Cms\Contracts\Hooks\TransformHook -->
<!-- extension-point: Cbox\Cms\Contracts\Hooks\ValidateHook -->

A hook lets a module, an addon or the application take part in a command it does not own: deny it, derive fields of the revision it writes, or add a rule of its own (GUARDRAILS 2.4, PRD 6.2, 6.3). The kernel owns the command pipeline; a hook only answers, and the kernel decides what its answer does. Hooks are deterministic and do no IO. Work that needs IO, such as calling another service, belongs in a subscriber of the event log that can issue a new command.

A hook is a class with `#[Hook(command: ..., phase: ..., priority: ..., budgetMs: ...)]` that implements the interface of its phase. `cms:build` registers it; see [build declarations](build-declarations.md) for the attribute and for `registry_not_a_hook`, the build error for a hook that does not implement its phase's interface.

| Phase | Interface | It returns | The kernel |
|---|---|---|---|
| `Phase::Authorize` | `AuthorizeHook::authorize(PlanView)` | `HookDecision::noObjection()` or `HookDecision::deny($reason)` | rejects the command as `unauthorized` with the reason on a denial. There is no grant: the kernel's own authorization runs first, and a hook can only add a refusal. |
| `Phase::Transform` | `TransformHook::transform(PlanView)` | `FieldChanges`, a list of `FieldChange` | sets each field in the plan, in order, and gives the next hook the changed plan. |
| `Phase::Validate` | `ValidateHook::validate(PlanView)` | `HookErrors`, a list of `HookError` | adds each error as `validation_hook_failed` after its own errors, which a hook cannot remove, and rejects the command as `validation_failed` when there is any error. |

## When hooks run

A write goes through the phases of the pipeline (PRD 6.2). The hooks run in these places:

1. The kernel resolves the actor and the aggregates, authorizes the command with its own rules and asks the action for the plan. It checks that every aggregate the plan changes was read and that every revision's type exists.
2. The authorize hooks run on that pending plan. A denial stops the command.
3. The transform hooks run, each on the plan as the hooks before it left it.
4. The kernel validates the fields of every revision against the type's generated validator, on the plan as the transforms left it, so a transform can never commit fields that break a rule of the blueprint (invariant 12). Then the validate hooks run on the same plan.
5. A dry run ends with that plan; otherwise the kernel commits it.

Within a phase, hooks run by priority with the lowest first, then by Composer package name, then by class name, so the order never depends on the order packages were installed in. A hook runs for the name and version of the command its attribute names; a new version of a command runs none of the old version's hooks.

## The view of the plan

A hook never gets the plan itself. It gets a `PlanView`: the command's name and version, the principal the command runs for, the call's classification access and the plan's mutations in the order the kernel applies them, with sub-plans flattened. `revisions()` gives the `RevisionCreated` mutations, and `revision($variant)` the one for a variant.

A release names a revision that is stored already, so its mutation, `VariantReleased`, holds no fields. The kernel reads the revision each release names once, before the hooks run, and the view holds it: `releases()` gives each `ReleasedRevision`, the release with the fields of its revision, and `release($variant)` the one for a variant. That is how a validate hook requires a field at the release that the entry's commands never require, such as an addon's extension field, which the owner's code creates and revises entries without (PRD 11.12, invariant 36). A release whose revision the kernel cannot read, one the variant does not have or one written under another schema version, is not among them; the kernel rejects that release after the validate hooks. A transform hook cannot change a released revision: a release changes no field.

The view is filtered to the classification access of the call (PRD 12.2). Every revision's fields, a released revision's included, hold only the fields whose classification, as the type catalog gives it, the access allows; a field above it is absent, as if the revision did not set it. A hook of an actor whose access is internal never sees a confidential field, and cannot change it. In a call an agent issues, the view also leaves out every field and nested field whose blueprint says `agents: false`, and a change that sets one is refused. A hook of an addon gets less when its [manifest](manifest.md) lets it read less: the view holds the fields up to the lower of the call's access and the manifest's `reads`, and the view's classification access is that lower one (invariant 21). The view is read-only: every class in it is `final readonly`.

## What a transform may change

A `FieldChange` sets one top-level field of the revision the plan writes for a variant: `FieldChange::own($variant, $handle, $value)` for a field of the type's owner, `FieldChange::extension($variant, $namespace, $handle, $value)` for a field an extender adds. A group is set as a whole. A later change of the same field wins.

That is all a hook can ask for, so it cannot change the actor, the grants, a classification or an aggregate the plan does not touch (invariant 12). The kernel refuses the command with `hook_change_refused`, and commits nothing, when a change names:

- a variant the plan writes no revision of, or writes more than one of;
- a field the revision's type does not declare, in the owner's fields or in the namespace given;
- a field classified above the classification access of the call, or, for a hook of an addon, above what its manifest lets it read.
- in a call an agent issues, a field or nested field whose blueprint says `agents: false`.

The error names the hook, its package and the field, because the refusal is a bug in the hook, not in the caller's input.

## Budgets

Every hook has the time budget its attribute gives, at most 20 ms, and all hooks of one command have 100 ms together (PRD 6.3, 13.6). The kernel measures each hook with PHP's monotonic timer, `hrtime`, not with the clock. A hook that takes longer than its budget, or that takes the command's hooks past 100 ms, rejects the command with `hook_budget_exceeded`, and nothing is committed. PHP cannot stop a hook while it runs, so the budget decides whether the command goes on, not how long the hook may take.

Each overrun is recorded in the application's log as a warning with the message `hook_budget_exceeded` and a context that names the command and its version, the hook, its package and phase, which budget it went over (`hook` or `command`), the budget in milliseconds and the times in nanoseconds, so which package makes commands slow is a lookup.

## Example

A package declares the command its hooks run for, here in the same package:

<!-- example-file: examples/Unit/Hooks/PublishStory.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The command the hooks on this page run for, version 1 of story.publish.
 */
#[Command('story.publish', version: 1)]
final readonly class PublishStory implements CommandInput {}
```

An authorize hook that denies an agent:

<!-- example-file: examples/Unit/Hooks/NoAgentPublishing.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;

/**
 * Denies publishing to a credential an agent was issued. It can only deny: when it has no
 * objection, the kernel's own authorization still decides.
 */
#[Hook(command: PublishStory::class, phase: Phase::Authorize, priority: 0, budgetMs: 1)]
final readonly class NoAgentPublishing implements AuthorizeHook
{
    public function authorize(PlanView $plan): HookDecision
    {
        return $plan->principal instanceof ActorPrincipal && $plan->principal->issuerKind === IssuerKind::Agent
            ? HookDecision::deny('Stories are published by a person, not by an agent.')
            : HookDecision::noObjection();
    }
}
```

A transform hook that derives a field:

<!-- example-file: examples/Unit/Hooks/DeriveSlug.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;

/**
 * Derives the slug of every revision the plan writes from its title, when the revision sets no
 * slug. It is deterministic and does no IO; the kernel validates the slug with the rest of the
 * fields after it.
 */
#[Hook(command: PublishStory::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class DeriveSlug implements TransformHook
{
    public function transform(PlanView $plan): FieldChanges
    {
        $changes = [];

        foreach ($plan->revisions() as $revision) {
            $title = $revision->fields->own->get(new FieldHandle('title'));

            if ($title instanceof TextValue && $revision->fields->own->get(new FieldHandle('slug')) === null) {
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title->value)), '-');
                $changes[] = FieldChange::own(new VariantRef($revision->entry, $revision->variant), new FieldHandle('slug'), new TextValue($slug));
            }
        }

        return new FieldChanges(...$changes);
    }
}
```

A validate hook with a rule of its own:

<!-- example-file: examples/Unit/Hooks/NoShoutedTitles.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;

/**
 * Adds an error for a title written in capitals only, a rule the blueprint cannot express. The
 * kernel adds it after its own errors as validation_hook_failed at fields.title.
 */
#[Hook(command: PublishStory::class, phase: Phase::Validate, priority: 0, budgetMs: 1)]
final readonly class NoShoutedTitles implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->revisions() as $revision) {
            $title = $revision->fields->own->get(new FieldHandle('title'));

            if ($title instanceof TextValue && preg_match('/[a-z]/', $title->value) !== 1 && preg_match('/[A-Z]{2}/', $title->value) === 1) {
                $errors[] = HookError::onField(new FieldHandle('title'), 'Write the title in sentence case, not in capitals.');
            }
        }

        return new HookErrors(...$errors);
    }
}
```

A hook is a plain class, so its test gives it the view the kernel would give it and checks the answer:

<!-- example: examples/Unit/Hooks/HooksTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Examples\Unit\Hooks\DeriveSlug;
use Examples\Unit\Hooks\NoAgentPublishing;
use Examples\Unit\Hooks\NoShoutedTitles;
use Examples\Unit\Hooks\PublishStory;

// A hook is a plain class: give it the view the kernel would give it and check its answer. The
// view below is what an actor with internal access sees of a plan that writes one revision.

function storyView(string $title, IssuerKind $issuer = IssuerKind::Service): PlanView
{
    return new PlanView(
        new CommandName('story.publish'),
        1,
        new ActorPrincipal(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000b1'), [], $issuer, ClassificationAccess::Confidential),
        ClassificationAccess::Internal,
        new RevisionCreated(
            EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'),
            TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d1'),
            VariantKey::shared(),
            RevisionNumber::first(),
            new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue($title)))),
        ),
    );
}

it('denies publishing to an agent and has no objection otherwise', function (): void {
    $hook = new NoAgentPublishing;

    expect($hook->authorize(storyView('A quiet week', IssuerKind::Agent))->reason)->toBe('Stories are published by a person, not by an agent.')
        ->and($hook->authorize(storyView('A quiet week'))->denies())->toBeFalse();
});

it('derives the slug from the title', function (): void {
    $changes = new DeriveSlug()->transform(storyView('A quiet week, mostly'))->changes;

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toEqual(FieldChange::own(
            new VariantRef(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'), VariantKey::shared()),
            new FieldHandle('slug'),
            new TextValue('a-quiet-week-mostly'),
        ));
});

it('adds an error for a title in capitals only', function (): void {
    $errors = new NoShoutedTitles()->validate(storyView('BREAKING NEWS'))->errors;

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->handle?->value)->toBe('title')
        ->and($errors[0]->message)->toBe('Write the title in sentence case, not in capitals.')
        ->and(new NoShoutedTitles()->validate(storyView('Breaking news'))->isEmpty())->toBeTrue();
});

it('declares each hook for its command, phase, priority and budget', function (string $class, Phase $phase, int $priority, int $budgetMs): void {
    /** @var class-string $class */
    $hook = new ReflectionClass($class)->getAttributes(Hook::class)[0]->newInstance();

    expect($hook->command)->toBe(PublishStory::class)
        ->and($hook->phase)->toBe($phase)
        ->and($hook->priority)->toBe($priority)
        ->and($hook->budgetMs)->toBe($budgetMs)
        ->and(is_a($class, $phase->hookInterface(), true))->toBeTrue();
})->with([
    [NoAgentPublishing::class, Phase::Authorize, 0, 1],
    [DeriveSlug::class, Phase::Transform, 10, 2],
    [NoShoutedTitles::class, Phase::Validate, 0, 1],
]);
```

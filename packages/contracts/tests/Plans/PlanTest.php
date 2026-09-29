<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Plans;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Mutations\VariantUnreleased;
use Cbox\Cms\Contracts\Plans\Plan;

/*
 * The Plan and its mutations (GUARDRAILS 2.1, PRD 6.2): a plan keeps its steps in order, sub-plans
 * from other planners keep their place, and every mutation names the aggregate it changes.
 */

function planUuid(int $n): string
{
    return sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', $n);
}

function planEntry(): EntryId
{
    return EntryId::fromString(planUuid(1));
}

function planRevision(int $n): RevisionCreated
{
    return new RevisionCreated(planEntry(), VariantKey::shared(), new RevisionNumber($n), new FieldValues);
}

/**
 * @return list<int>
 */
function planOrder(Plan $plan): array
{
    return array_map(
        static fn (Mutation $mutation): int => $mutation instanceof RevisionCreated ? $mutation->revision->value : 0,
        $plan->mutations(),
    );
}

it('keeps its mutations in the order given', function (): void {
    expect(planOrder(new Plan(planRevision(1), planRevision(2), planRevision(3))))->toBe([1, 2, 3])
        ->and(planOrder(new Plan(planRevision(3), planRevision(1), planRevision(2))))->toBe([3, 1, 2]);
});

it('applies each sub-plan\'s mutations where the sub-plan stands, depth first', function (): void {
    $inner = new Plan(planRevision(3), new Plan(planRevision(4)), planRevision(5));
    $plan = new Plan(planRevision(1), new Plan(planRevision(2)), $inner, planRevision(6));

    expect(planOrder($plan))->toBe([1, 2, 3, 4, 5, 6])
        ->and($plan->steps)->toHaveCount(4)
        ->and($plan->steps[2])->toBe($inner);
});

it('composes with then() after its own steps and leaves itself unchanged', function (): void {
    $first = new Plan(planRevision(1));
    $composed = $first->then(new Plan(planRevision(2), planRevision(3)), planRevision(4));

    expect(planOrder($first))->toBe([1])
        ->and(planOrder($composed))->toBe([1, 2, 3, 4])
        ->and($composed->steps)->toHaveCount(3)
        ->and(planOrder(Plan::empty()->then(planRevision(7))))->toBe([7]);
});

it('is empty when neither it nor a sub-plan has a mutation', function (): void {
    expect(Plan::empty()->isEmpty())->toBeTrue()
        ->and(Plan::empty()->steps)->toBe([])
        ->and(new Plan(Plan::empty(), new Plan(Plan::empty()))->isEmpty())->toBeTrue()
        ->and(new Plan(Plan::empty(), new Plan(planRevision(1)))->isEmpty())->toBeFalse()
        ->and(new Plan(planRevision(1))->isEmpty())->toBeFalse();
});

it('names the aggregate each mutation changes', function (): void {
    $entry = planEntry();
    $variant = VariantKey::of(new Locale('da'));
    $placement = PlacementId::fromString(planUuid(2));
    $actor = ActorId::fromString(planUuid(3));
    $node = NodeId::fromString(planUuid(4));
    $site = SiteId::fromString(planUuid(5));
    $variantKey = new VariantRef($entry, $variant)->aggregateKey();

    $mutations = [
        [new EntryCreated($entry, TypeId::fromString(planUuid(6)), $node), 'entry:'.planUuid(1)],
        [new RevisionCreated($entry, $variant, RevisionNumber::first(), new FieldValues), $variantKey],
        [new HeadMoved($entry, $variant, null, RevisionNumber::first()), $variantKey],
        [new VariantReleased($entry, $variant, RevisionNumber::first()), $variantKey],
        [new VariantUnreleased($entry, $variant, RevisionNumber::first()), $variantKey],
        [new PlacementCreated($placement, $entry, $node, $site), 'placement:'.planUuid(2)],
        [new PlacementWindowSet($placement, new Locale('da'), TimeWindow::always()), 'placement:'.planUuid(2)],
        [new ActorDeactivated($actor), 'actor:'.planUuid(3)],
    ];

    foreach ($mutations as [$mutation, $key]) {
        expect($mutation->aggregate()->aggregateKey())->toBe($key);
    }

    expect($variantKey)->toBe('variant:'.planUuid(1).':da');
});

it('moves a head only to another revision', function (): void {
    $moved = new HeadMoved(planEntry(), VariantKey::shared(), new RevisionNumber(1), new RevisionNumber(2));

    expect($moved->from?->value)->toBe(1)
        ->and($moved->to->value)->toBe(2)
        ->and(new HeadMoved(planEntry(), VariantKey::shared(), null, RevisionNumber::first())->from)->toBeNull()
        ->and(static fn (): HeadMoved => new HeadMoved(planEntry(), VariantKey::shared(), new RevisionNumber(3), new RevisionNumber(3)))
        ->toThrow(InvalidMutation::class, 'A head move goes to another revision, but both are revision 3.');
});

it('keeps steps given by name as a list', function (): void {
    $plan = new Plan(...['first' => planRevision(1), 'second' => planRevision(2)]);
    $then = $plan->then(...['third' => planRevision(3)]);

    expect(array_keys($plan->steps))->toBe([0, 1])
        ->and(array_keys($then->steps))->toBe([0, 1, 2])
        ->and(planOrder($then))->toBe([1, 2, 3]);
});

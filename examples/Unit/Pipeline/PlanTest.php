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

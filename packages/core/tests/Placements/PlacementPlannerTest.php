<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementLocaleAdded;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\CreatePlacementAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;

/*
 * The canonical moves the placement planner makes when the entry's placements in the locale do not
 * yet follow the rule: a created placement leaves the flag to an older placement the rule chooses,
 * and a window on a placement the read did not list counts that placement among the others.
 */

const PLANNED_ENTRY = '0192a0c0-0000-7000-8000-00000000f0e1';
const PLANNED_FIRST = '0192a0c0-0000-7000-8000-00000000f0b1';
const PLANNED_SECOND = '0192a0c0-0000-7000-8000-00000000f0b2';
const PLANNED_NEW = '0192a0c0-0000-7000-8000-00000000f0b9';

function plannedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-01T12:00:00Z');
}

function plannedState(string $placement, Visibility $visibility, bool $canonical): PlacementState
{
    $window = $visibility === Visibility::Live ? new TimeWindow(new DateTimeImmutable('2026-09-01T00:00:00Z')) : null;

    return new PlacementState(PlacementId::fromString($placement), new AggregateVersion(1), $visibility, $window, $canonical);
}

/**
 * The plan of placement.create of PLANNED_NEW in da, with the entry's other placements there.
 */
function plannedCreate(PlacementState ...$states): Plan
{
    $da = new Locale('da');
    $command = new CreatePlacement(PlacementId::fromString(PLANNED_NEW), EntryId::fromString(PLANNED_ENTRY), NodeId::fromString('0192a0c0-0000-7000-8000-00000000f0a1'), SiteId::fromString('0192a0c0-0000-7000-8000-00000000f0c1'), [new LocaleSlug($da, new Slug('harbour'))]);
    $aggregates = new CreatePlacementAggregates($command->placement, null, $command->entry, new AggregateVersion(1), $command->node, null, $command->site, null, [], [new LocalePlacements($command->entry, $da, array_values($states))], plannedAt());

    return new PlacementPlanner()->create($command, $aggregates);
}

/**
 * Each mutation of a plan as text.
 *
 * @return list<string>
 */
function plannedSteps(Plan $plan): array
{
    return array_map(static fn (object $mutation): string => match (true) {
        $mutation instanceof PlacementCreated => 'created '.$mutation->placement->toString(),
        $mutation instanceof PlacementLocaleAdded => sprintf('added %s canonical %s', $mutation->placement->toString(), $mutation->canonical ? 'yes' : 'no'),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s', $mutation->placement->toString(), $mutation->canonical ? 'set' : 'cleared'),
        $mutation instanceof PlacementWindowSet => 'window '.$mutation->placement->toString(),
        default => $mutation::class,
    }, $plan->mutations());
}

it('gives the flag to the older placement the rule chooses when none holds it, and the new one stays without it', function (): void {
    expect(plannedSteps(plannedCreate(plannedState(PLANNED_FIRST, Visibility::Hidden, false))))->toBe([
        'created '.PLANNED_NEW,
        'added '.PLANNED_NEW.' canonical no',
        'canonical '.PLANNED_FIRST.' set',
    ]);
});

it('moves the flag from a hidden placement to a visible one while it creates another', function (): void {
    expect(plannedSteps(plannedCreate(plannedState(PLANNED_FIRST, Visibility::Hidden, true), plannedState(PLANNED_SECOND, Visibility::Live, false))))->toBe([
        'created '.PLANNED_NEW,
        'canonical '.PLANNED_FIRST.' cleared',
        'added '.PLANNED_NEW.' canonical no',
        'canonical '.PLANNED_SECOND.' set',
    ]);
});

it('counts a placement the read did not list among the others when its window makes it visible', function (): void {
    $da = new Locale('da');
    $placements = new LocalePlacements(EntryId::fromString(PLANNED_ENTRY), $da, [plannedState(PLANNED_SECOND, Visibility::Hidden, true)]);
    $window = new TimeWindow(new DateTimeImmutable('2026-09-01T00:00:00Z'));

    $plan = new PlacementPlanner()->setWindow(PlacementId::fromString(PLANNED_FIRST), $da, $window, $placements, plannedAt());

    expect(plannedSteps($plan))->toBe([
        'window '.PLANNED_FIRST,
        'canonical '.PLANNED_SECOND.' cleared',
        'canonical '.PLANNED_FIRST.' set',
    ]);
});

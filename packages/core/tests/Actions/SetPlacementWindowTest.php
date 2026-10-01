<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\EntryReleaseRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Tests\Placements\PlacementActionWorld as World;

/*
 * placement.set_window's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.7, 5.10,
 * 6.4): it reads the placement and every placement of its entry in the locale and plans the
 * window, moving the canonical flag so a visible placement holds it whenever one is visible
 * (invariant 14). It is rejected for a placement below a node the actor's grants do not reach, a
 * locale the placement does not have, a withdrawn placement (invariant 7), a window that would make
 * the placement live or scheduled while its entry is not active with a released revision
 * (invariant 6) and a window from an agent (invariant 18), and it is version_conflict for a stale
 * version and for a read that went stale before the commit, the entry's release included.
 */

const SOUTH_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000000561';

/**
 * @return list<string> each error as "<code> <path>"
 */
function windowErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function windowSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof PlacementWindowSet => sprintf('window %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->window instanceof TimeWindow ? 'set' : 'none'),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->canonical ? 'set' : 'cleared'),
        default => $mutation::class,
    }, $mutations);
}

it('plans the window of a canonical placement and reads the entry\'s placements in the locale', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true, version: 2)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false, version: 5);
    $window = World::window(0, 24);

    $result = $world->setWindow(World::PLACEMENT, 2, $window);
    $pending = $world->committed();
    $canonical = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('placement.set_window')
        ->and($pending->plan->mutations())->toEqual([new PlacementWindowSet(World::placement(), new Locale('da'), $window)])
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(2)))
        ->and($pending->reads->of(World::placement(SOUTH_PLACEMENT)))->toEqual(ReadVersion::at(World::placement(SOUTH_PLACEMENT), new AggregateVersion(5)))
        ->and($pending->reads->of($canonical))->toEqual(ReadVersion::at($canonical, new AggregateVersion(1)))
        ->and($pending->reads->of(new EntryReleaseRef(World::entry())))->toEqual(ReadVersion::at(new EntryReleaseRef(World::entry()), new AggregateVersion(World::RELEASE_VERSION)));
});

it('refuses a window that would make a placement live or scheduled while the entry has no released revision, and moves no canonical flag', function (ReleaseState $release): void {
    $world = new World()
        ->release(EntryLifecycle::Active, $release)
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false);

    $live = $world->setWindow(SOUTH_PLACEMENT, 1, World::window(-1));
    $scheduled = $world->setWindow(SOUTH_PLACEMENT, 1, World::window(24, 48));

    expect(windowErrors($live))->toBe(['validation_failed window'])
        ->and($live->errors[0]->message)->toContain('invariant 6')->toContain('entry.publish')->toContain(World::ENTRY)
        ->and(windowErrors($scheduled))->toBe(['validation_failed window'])
        ->and($world->committer->pending)->toBe([]);
})->with([
    'never released' => [ReleaseState::Unreleased],
    'withdrawn' => [ReleaseState::Withdrawn],
]);

it('refuses a window for an entry that is not active or has no shared head', function (EntryLifecycle $lifecycle, ?ReleaseState $release): void {
    $world = new World()
        ->release($lifecycle, $release)
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true);

    $result = $world->setWindow(World::PLACEMENT, 1, World::window(0));

    expect(windowErrors($result))->toBe(['validation_failed window'])
        ->and($world->committer->pending)->toBe([]);
})->with([
    'archived but released' => [EntryLifecycle::Archived, ReleaseState::Released],
    'tombstoned' => [EntryLifecycle::Tombstoned, ReleaseState::Released],
    'no shared head' => [EntryLifecycle::Active, null],
]);

it('hides a placement and sets a window that has ended whatever the entry\'s release, without reading it', function (): void {
    $world = new World()
        ->release(EntryLifecycle::Active, ReleaseState::Unreleased)
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-2), true);

    $hidden = $world->setWindow(World::PLACEMENT, 1, null);
    $ended = $world->setWindow(World::PLACEMENT, 1, World::window(-5, -2));

    expect($hidden->outcome())->toBe(Outcome::Committed)
        ->and($ended->outcome())->toBe(Outcome::Committed)
        ->and(array_map(static fn (PendingChangeset $pending): ?ReadVersion => $pending->reads->of(new EntryReleaseRef(World::entry())), $world->committer->pending))->toBe([null, null]);
});

it('is version_conflict when the entry\'s release changed before the commit', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->commitWith(new VersionConflict(new StaleRead(new EntryReleaseRef(World::entry()), new AggregateVersion(World::RELEASE_VERSION), new AggregateVersion(World::RELEASE_VERSION + 1))));

    $result = $world->setWindow(World::PLACEMENT, 1, World::window(0));

    expect(windowErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('entry_release:'.World::ENTRY);
});

it('moves the canonical flag to the placement that becomes visible when the canonical one is not', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false);

    $world->setWindow(SOUTH_PLACEMENT, 1, World::window(-1));

    expect(windowSteps($world->committed()->plan->mutations()))->toBe([
        'window '.SOUTH_PLACEMENT.' da set',
        'canonical '.World::PLACEMENT.' da cleared',
        'canonical '.SOUTH_PLACEMENT.' da set',
    ]);
});

it('keeps the canonical flag with a visible placement, and with a hidden one while none is visible', function (): void {
    $visible = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-2), true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false);
    $visible->setWindow(SOUTH_PLACEMENT, 1, World::window(-1));

    $scheduled = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false);
    $scheduled->setWindow(SOUTH_PLACEMENT, 1, World::window(2));

    expect(windowSteps($visible->committed()->plan->mutations()))->toBe(['window '.SOUTH_PLACEMENT.' da set'])
        ->and(windowSteps($scheduled->committed()->plan->mutations()))->toBe(['window '.SOUTH_PLACEMENT.' da set']);
});

it('moves the canonical flag off a placement it hides while another is visible', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-2), true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Live, World::window(-1), false);

    $world->setWindow(World::PLACEMENT, 1, null);

    expect(windowSteps($world->committed()->plan->mutations()))->toBe([
        'window '.World::PLACEMENT.' da none',
        'canonical '.World::PLACEMENT.' da cleared',
        'canonical '.SOUTH_PLACEMENT.' da set',
    ]);
});

it('rejects a window from an agent, now or later, and lets an agent hide a placement', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true);

    $now = $world->setWindow(World::PLACEMENT, 1, World::window(0), agent: true);
    $later = $world->setWindow(World::PLACEMENT, 1, World::window(48), agent: true);
    $hidden = $world->setWindow(World::PLACEMENT, 1, null, agent: true);

    expect(windowErrors($now))->toBe(['agent_visibility_forbidden -'])
        ->and($now->errors[0]->message)->toContain('placement:'.World::PLACEMENT)
        ->and(windowErrors($later))->toBe(['agent_visibility_forbidden -'])
        ->and($hidden->outcome())->toBe(Outcome::Committed)
        ->and(count($world->committer->pending))->toBe(1);
});

it('rejects a placement below a node the actor\'s grants do not reach', function (): void {
    $world = new World()->place(World::PLACEMENT, World::FAR, 'harbour', Visibility::Hidden, null, true);

    $result = $world->setWindow(World::PLACEMENT, 1, World::window(0));

    expect(windowErrors($result))->toBe(['unauthorized placement'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a locale the placement does not have and a withdrawn placement', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Withdrawn, null, false);

    $locale = $world->setWindow(World::PLACEMENT, 1, World::window(0), 'en');
    $withdrawn = $world->setWindow(SOUTH_PLACEMENT, 1, World::window(0));

    expect(windowErrors($locale))->toBe(['validation_failed locale'])
        ->and(windowErrors($withdrawn))->toBe(['validation_failed locale'])
        ->and($withdrawn->errors[0]->message)->toContain('withdrawn')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a version the placement is no longer at', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true, version: 3);

    $result = $world->setWindow(World::PLACEMENT, 2, World::window(0));

    expect(windowErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.World::PLACEMENT)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another placement of the entry changed before the commit', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true)
        ->place(SOUTH_PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, false)
        ->commitWith(new VersionConflict(new StaleRead(World::placement(SOUTH_PLACEMENT), new AggregateVersion(1), new AggregateVersion(2))));

    $result = $world->setWindow(World::PLACEMENT, 1, World::window(0));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(windowErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.SOUTH_PLACEMENT);
});

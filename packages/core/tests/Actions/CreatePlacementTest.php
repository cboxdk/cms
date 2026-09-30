<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementLocaleAdded;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Tests\Placements\PlacementActionWorld as World;

/*
 * placement.create's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.7, 5.9, 5.10):
 * a create reads the placement as absent, the node, the site, each slug below the node and the
 * entry's placements in each locale, and plans the placement hidden with a slug per locale, making
 * it canonical when the entry has no canonical placement in the locale (invariant 14). It is
 * rejected for a slug another placement has below the node (invariant 15), a node the actor's
 * grants do not reach, and a node, site, locale or entry that does not fit, and it is
 * version_conflict for an id that exists and for a read that went stale before the commit.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function createPlacementErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function createPlacementSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof PlacementCreated => 'created '.$mutation->placement->toString().' below '.$mutation->node->toString(),
        $mutation instanceof PlacementLocaleAdded => sprintf('locale %s %s %s%s', $mutation->placement->toString(), $mutation->locale->value, $mutation->slug->value, $mutation->canonical ? ' canonical' : ''),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->canonical ? 'set' : 'cleared'),
        default => $mutation::class,
    }, $mutations);
}

it('plans a hidden placement with its slugs, canonical in each locale where the entry has none, and reads what it decides on', function (): void {
    $world = new World;

    $result = $world->create(slugs: ['da' => 'harbour', 'en' => 'harbour-opens']);
    $pending = $world->committed();
    $slug = new PlacementSlugRef(World::node(World::NORTH_NODE), new Locale('da'), new Slug('harbour'));
    $canonical = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('placement.create')
        ->and(createPlacementSteps($pending->plan->mutations()))->toBe([
            'created '.World::PLACEMENT.' below '.World::NORTH_NODE,
            'locale '.World::PLACEMENT.' da harbour canonical',
            'locale '.World::PLACEMENT.' en harbour-opens canonical',
        ])
        ->and($pending->plan->mutations()[0])->toEqual(new PlacementCreated(World::placement(), World::entry(), World::node(World::NORTH_NODE), World::site(World::NORTH)))
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::absent(World::placement()))
        ->and($pending->reads->of(World::node(World::NORTH_NODE)))->toEqual(ReadVersion::at(World::node(World::NORTH_NODE), new AggregateVersion(1)))
        ->and($pending->reads->of(World::site(World::NORTH)))->toEqual(ReadVersion::at(World::site(World::NORTH), new AggregateVersion(1)))
        ->and($pending->reads->of($slug))->toEqual(ReadVersion::absent($slug))
        ->and($pending->reads->of($canonical))->toEqual(ReadVersion::absent($canonical))
        ->and($pending->reads->of(World::entry()))->toBeNull();
});

it('leaves the canonical flag with the entry\'s placement that has it, and reads that placement', function (): void {
    $south = '01936f5e-8a2b-7c3d-9e4f-000000000561';
    $world = new World()->place($south, World::SOUTH_NODE, 'harbour', Visibility::Live, World::window(-1), true, version: 3);

    $world->create();
    $pending = $world->committed();
    $canonical = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect(createPlacementSteps($pending->plan->mutations()))->toBe([
        'created '.World::PLACEMENT.' below '.World::NORTH_NODE,
        'locale '.World::PLACEMENT.' da harbour',
    ])
        ->and($pending->reads->of($canonical))->toEqual(ReadVersion::at($canonical, new AggregateVersion(1)))
        ->and($pending->reads->of(World::placement($south)))->toEqual(ReadVersion::at(World::placement($south), new AggregateVersion(3)));
});

it('lets an agent create a placement, which is hidden', function (): void {
    $world = new World;

    expect($world->create(agent: true)->outcome())->toBe(Outcome::Committed);
});

it('rejects a slug another placement has below the node in the locale, and commits nothing', function (): void {
    $world = new World()->place('01936f5e-8a2b-7c3d-9e4f-000000000562', World::NORTH_NODE, 'harbour', Visibility::Hidden, null, true);

    $result = $world->create(slugs: ['da' => 'harbour', 'en' => 'harbour']);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(createPlacementErrors($result))->toBe(['placement_slug_taken slugs[0].slug'])
        ->and($world->committer->pending)->toBe([]);
});

it('takes a slug a withdrawn placement had below the node', function (): void {
    $world = new World()->place('01936f5e-8a2b-7c3d-9e4f-000000000562', World::NORTH_NODE, 'harbour', Visibility::Withdrawn, null, false);

    expect($world->create()->outcome())->toBe(Outcome::Committed);
});

it('rejects a node the actor\'s grants do not reach, whatever the entry\'s home', function (): void {
    $world = new World;

    $result = $world->create(node: World::FAR, site: World::SOUTH);

    expect(createPlacementErrors($result))->toBe(['unauthorized node'])
        ->and($result->errors[0]->message)->toContain(World::FAR)
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a node outside the site, a mount, a locale the site does not publish in and a locale given twice', function (): void {
    $world = new World;

    expect(createPlacementErrors($world->create(node: World::SOUTH_NODE, site: World::NORTH)))->toBe(['validation_failed node'])
        ->and(createPlacementErrors($world->create(node: World::MOUNT)))->toBe(['validation_failed node'])
        ->and(createPlacementErrors($world->create(node: World::SOUTH_NODE, site: World::SOUTH, slugs: ['da' => 'harbour', 'en' => 'harbour'])))->toBe(['validation_failed slugs[1].locale'])
        ->and(createPlacementErrors($world->create(site: '01936f5e-8a2b-7c3d-9e4f-000000000509')))->toBe(['validation_failed site'])
        ->and(createPlacementErrors($world->create(slugs: [])))->toBe(['validation_failed slugs'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an entry the actor cannot read', function (): void {
    $world = new World;

    $result = $world->run(new CreatePlacement(
        World::placement(),
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000549'),
        World::node(World::NORTH_NODE),
        World::site(World::NORTH),
        [new LocaleSlug(new Locale('da'), new Slug('harbour'))],
    ));

    expect(createPlacementErrors($result))->toBe(['validation_failed entry'])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a placement id that exists', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SOUTH_NODE, 'harbour', Visibility::Hidden, null, true);

    $result = $world->create();

    expect(createPlacementErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.World::PLACEMENT)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another call took the slug before the commit', function (): void {
    $slug = new PlacementSlugRef(World::node(World::NORTH_NODE), new Locale('da'), new Slug('harbour'));
    $world = new World()->commitWith(new VersionConflict(new StaleRead($slug, null, new AggregateVersion(1))));

    $result = $world->create();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(createPlacementErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement_slug:'.World::NORTH_NODE.':da:harbour');
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementSlugSet;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Placements\Actions\SetPlacementSlugAction;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Tests\Placements\PlacementActionWorld as World;
use ReflectionAttribute;
use ReflectionClass;

/*
 * placement.set_slug's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.9, 5.10,
 * invariant 15): it reads the placement and whether the new slug is taken below its node, and
 * plans the rename, which makes nothing public, so an agent may rename a visible placement. It is
 * rejected for a placement below a node the actor's grants do not reach, a locale the placement
 * does not have, a withdrawn placement (invariant 7), the slug the placement has already and a
 * slug another placement has, and it is version_conflict for a stale version and for a slug
 * claimed before the commit.
 */

const RENAMED_NEIGHBOUR = '01936f5e-8a2b-7c3d-9e4f-000000000562';

/**
 * @return list<string> each error as "<code> <path>"
 */
function slugErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

it('plans the new slug of a visible placement, also from an agent, and reads the placement and the slug as free', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true, version: 2);
    $slug = new PlacementSlugRef(World::node(World::NORTH_NODE), new Locale('da'), new Slug('quay'));

    $result = $world->setSlug(World::PLACEMENT, 2, 'quay', agent: true);
    $pending = $world->committed();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('placement.set_slug')
        ->and($pending->plan->mutations())->toEqual([new PlacementSlugSet(World::placement(), new Locale('da'), new Slug('quay'))])
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(2)))
        ->and($pending->reads->of($slug))->toEqual(ReadVersion::absent($slug))
        ->and($pending->reads->of($world->editor))->not->toBeNull()
        ->and(count($pending->reads->reads))->toBe(3);
});

it('rejects a slug another placement that is not withdrawn has below the node with placement_slug_taken', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true)
        ->place(RENAMED_NEIGHBOUR, World::NORTH_NODE, 'quay', Visibility::Hidden, null, false);

    $result = $world->setSlug(World::PLACEMENT, 1, 'quay');

    expect(slugErrors($result))->toBe(['placement_slug_taken slug'])
        ->and($result->errors[0]->message)->toContain(World::NORTH_NODE)
        ->and($world->committer->pending)->toBe([]);
});

it('takes a slug only a withdrawn placement has', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true)
        ->place(RENAMED_NEIGHBOUR, World::NORTH_NODE, 'quay', Visibility::Withdrawn, null, false);

    $result = $world->setSlug(World::PLACEMENT, 1, 'quay');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(count($world->committer->pending))->toBe(1);
});

it('rejects the slug the placement has already, without reading the slug', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true);

    $result = $world->setSlug(World::PLACEMENT, 1, 'harbour');

    expect(slugErrors($result))->toBe(['validation_failed slug'])
        ->and($result->errors[0]->message)->toContain('already')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a placement below a node the actor\'s grants do not reach', function (): void {
    $world = new World()->place(World::PLACEMENT, World::FAR, 'harbour', Visibility::Live, World::window(-1), true);

    $result = $world->setSlug(World::PLACEMENT, 1, 'quay');

    expect(slugErrors($result))->toBe(['unauthorized placement'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a locale the placement does not have and a withdrawn placement', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true)
        ->place(RENAMED_NEIGHBOUR, World::SOUTH_NODE, 'harbour', Visibility::Withdrawn, null, false);

    $locale = $world->setSlug(World::PLACEMENT, 1, 'quay', 'en');
    $withdrawn = $world->setSlug(RENAMED_NEIGHBOUR, 1, 'quay');

    expect(slugErrors($locale))->toBe(['validation_failed locale'])
        ->and(slugErrors($withdrawn))->toBe(['validation_failed locale'])
        ->and($withdrawn->errors[0]->message)->toContain('withdrawn')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a version the placement is no longer at', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true, version: 3);

    $result = $world->setSlug(World::PLACEMENT, 2, 'quay');

    expect(slugErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.World::PLACEMENT)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another command claimed the slug before the commit', function (): void {
    $world = new World()->place(World::PLACEMENT, World::NORTH_NODE, 'harbour', Visibility::Live, World::window(-1), true);
    $world->committer->at(new PlacementSlugRef(World::node(World::NORTH_NODE), new Locale('da'), new Slug('quay')), AggregateVersion::first());

    $result = $world->setSlug(World::PLACEMENT, 1, 'quay');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(slugErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement_slug:'.World::NORTH_NODE.':da:quay');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new World;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new SetPlacementSlugAction($world->placements))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});

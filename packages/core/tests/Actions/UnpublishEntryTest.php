<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
use Cbox\Cms\Contracts\Plans\Mutations\VariantUnreleased;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Tests\Publishing\PublishingActionWorld as World;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.unpublish's action in the command pipeline with fakes (GUARDRAILS 9, PRD 6.2, 6.4): it is
 * composite, so its one plan takes the shared variant back to unreleased and closes every placement
 * of the entry that is visible now or later, in every locale and on every site, the ones below
 * nodes the actor's regions do not reach included, because unpublishing is decided on the entry's
 * home. Withdrawn, hidden and expired placements are left as they are. A type with stages none only
 * closes its placements. A call that has nothing to take back is rejected, and it is
 * version_conflict for a stale variant and for a placement that changed before the commit.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function unpublishErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function unpublishSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof VariantUnreleased => sprintf('unrelease %s %d', $mutation->variant->value, $mutation->revision->value),
        $mutation instanceof PlacementClosed => sprintf('close %s %s', $mutation->placement->toString(), $mutation->locale->value),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->canonical ? 'set' : 'cleared'),
        default => $mutation::class,
    }, $mutations);
}

function unpublishedWorld(): World
{
    return new World(new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(5), new RevisionNumber(5)));
}

it('takes the release back and closes every placement that is visible now or later, on every site, in one changeset', function (): void {
    $world = unpublishedWorld()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true], 'en' => [Visibility::Scheduled, World::window(4), true]], version: 3)
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Live, World::window(-1), false]], version: 2)
        ->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Scheduled, World::window(8), false]]);

    $result = $world->unpublish();
    $pending = $world->committed();
    $variant = new VariantRef(World::entry(), VariantKey::shared());
    $slot = new CanonicalPlacementRef(World::entry(), new Locale('en'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.unpublish')
        ->and(unpublishSteps($pending->plan->mutations()))->toBe([
            'unrelease shared 5',
            'close '.World::HOME_PLACEMENT.' da',
            'close '.World::AWAY_PLACEMENT.' da',
            'close '.World::FAR_PLACEMENT.' da',
            'close '.World::HOME_PLACEMENT.' en',
        ])
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::at($variant, new AggregateVersion(6)))
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(3)))
        ->and($pending->reads->of(World::placement(World::AWAY_PLACEMENT)))->toEqual(ReadVersion::at(World::placement(World::AWAY_PLACEMENT), new AggregateVersion(2)))
        ->and($pending->reads->of(World::placement(World::FAR_PLACEMENT)))->toEqual(ReadVersion::at(World::placement(World::FAR_PLACEMENT), new AggregateVersion(1)))
        ->and($pending->reads->of($slot))->toEqual(ReadVersion::at($slot, new AggregateVersion(1)));
});

it('leaves hidden, withdrawn and expired placements as they are, and the canonical flag where it is', function (): void {
    $world = unpublishedWorld()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Hidden, null, false], 'en' => [Visibility::Withdrawn, null, false]])
        ->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Live, World::window(-5, -1), false]]);

    $world->unpublish();

    expect(unpublishSteps($world->committed()->plan->mutations()))->toBe(['unrelease shared 5', 'close '.World::HOME_PLACEMENT.' da']);
});

it('only closes the placements of a type with stages none, and of content that is not released', function (): void {
    $unstaged = World::unstaged()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);
    $unreleased = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Scheduled, World::window(2), true]]);

    $unstaged->unpublish(version: 3);
    $unreleased->unpublish();

    expect(unpublishSteps($unstaged->committed()->plan->mutations()))->toBe(['close '.World::HOME_PLACEMENT.' da'])
        ->and(unpublishSteps($unreleased->committed()->plan->mutations()))->toBe(['close '.World::HOME_PLACEMENT.' da']);
});

it('takes the release back of content that has no placement', function (): void {
    $world = unpublishedWorld();

    $world->unpublish();

    expect(unpublishSteps($world->committed()->plan->mutations()))->toBe(['unrelease shared 5']);
});

it('rejects an unpublish that has nothing to take back or close', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    $result = $world->unpublish();

    expect(unpublishErrors($result))->toBe(['validation_failed -'])
        ->and($result->errors[0]->message)->toContain('changes nothing')
        ->and($world->committer->pending)->toBe([]);
});

it('computes the plan on a dry run and commits nothing', function (): void {
    $world = unpublishedWorld()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);

    $result = $world->unpublish(dryRun: true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and(unpublishSteps($result->dryRun?->plan->mutations() ?? []))->toBe(['unrelease shared 5', 'close '.World::HOME_PLACEMENT.' da'])
        ->and($result->dryRun?->visible)->toBe([])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a variant at another version', function (): void {
    $world = unpublishedWorld()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);

    $result = $world->unpublish(version: 5);

    expect(unpublishErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('variant:'.World::ENTRY.':shared')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when a placement of the entry changed before the commit', function (): void {
    $world = unpublishedWorld()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]])
        ->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Hidden, null, false]])
        ->commitWith(new VersionConflict(new StaleRead(World::placement(World::FAR_PLACEMENT), new AggregateVersion(1), new AggregateVersion(2))));

    $result = $world->unpublish();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(unpublishErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.World::FAR_PLACEMENT);
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces, the surfaces entry.publish is on', function (): void {
    $world = unpublishedWorld()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);
    $world->unpublish();
    $surfaces = static fn (object $action): array => array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass($action)->getAttributes(Action::class),
    );

    expect($world->committed()->command->value)->toBe('entry.unpublish')
        ->and($surfaces(new ReflectionClass(UnpublishEntryAction::class)->newInstanceWithoutConstructor()))->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]])
        ->and($surfaces(new ReflectionClass(UnpublishEntryAction::class)->newInstanceWithoutConstructor()))->toBe($surfaces(new ReflectionClass(PublishEntryAction::class)->newInstanceWithoutConstructor()));
});

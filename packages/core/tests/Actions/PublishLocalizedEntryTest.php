<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Actions\PublishLocalizedEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishLocalizedEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\VariantRelease;
use Cbox\Cms\Core\Tests\Publishing\PublishingActionWorld as World;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.publish version 2's action in the command pipeline with fakes (GUARDRAILS 9, PRD 6.2, 6.4,
 * 10.3): its one plan is the release of each variant it names, the shared one first, followed by
 * the home placement's window in the locale with the canonical move that window causes, leaving out
 * a variant whose released revision is the one named. It works for a type with localization
 * variants and one without. It is rejected for a list without the shared variant or the locale's,
 * with another language's, a revision the type cannot have or needs, an agent (invariant 18), and
 * it is version_conflict for a variant at another version.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function localizedPublishErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function localizedPublishSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof VariantReleased => sprintf('release %s %d', $mutation->variant->value, $mutation->revision->value),
        $mutation instanceof PlacementWindowSet => sprintf('window %s %s', $mutation->locale->value, $mutation->window instanceof TimeWindow ? ($mutation->window->from?->format('H:i') ?? 'open') : 'none'),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s', $mutation->locale->value, $mutation->canonical ? 'set' : 'cleared'),
        default => $mutation::class,
    }, $mutations);
}

it('plans the release of the shared variant and the locale\'s, then the home placement live, in one changeset', function (): void {
    $world = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, false]]);

    $result = $world->publishVariants(['da' => [5, 2], 'shared' => [6, 4]]);
    $pending = $world->committed();
    $da = new VariantRef(World::entry(), VariantKey::of(new Locale('da')));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.publish')
        ->and($pending->version)->toBe(2)
        ->and(localizedPublishSteps($pending->plan->mutations()))->toBe(['release shared 4', 'release da 2', 'window da 12:00', 'canonical da set'])
        ->and($pending->reads->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(5)))
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(1)));
});

it('leaves out the release of a variant whose released revision is the one named', function (): void {
    $world = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);
    $world->entries->withHead(World::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(7), new RevisionNumber(4), new RevisionNumber(5), new RevisionNumber(5)));

    $world->publishVariants(['shared' => [7, 5], 'da' => [5, 2]], window: World::window(2));

    expect(localizedPublishSteps($world->committed()->plan->mutations()))->toBe(['release da 2', 'window da 14:00']);
});

it('publishes the shared variant alone of a type with localization none', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    $world->publishVariants(['shared' => [6, 4]]);

    expect(localizedPublishSteps($world->committed()->plan->mutations()))->toBe(['release shared 4', 'window da 12:00']);
});

it('refuses a list without the shared variant or the locale\'s, another language, and a revision the type cannot have or needs', function (): void {
    $placed = static fn (World $world): World => $world->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    expect(localizedPublishErrors($placed(World::localized())->publishVariants(['da' => [5, 2]])))->toBe(['validation_failed variants'])
        ->and(localizedPublishErrors($placed(World::localized())->publishVariants(['shared' => [6, 4]])))->toBe(['validation_failed variants'])
        ->and(localizedPublishErrors($placed(World::localized())->publishVariants(['shared' => [6, 4], 'da' => [5, null]])))->toBe(['validation_failed variants[1].revision'])
        ->and(localizedPublishErrors($placed(new World)->publishVariants(['shared' => [6, 4], 'da' => [1, 1]])))->toBe(['version_conflict -'])
        ->and(localizedPublishErrors($placed(World::unstaged())->publishVariants(['shared' => [3, 1]])))->toBe(['validation_failed variants[0].revision']);
});

it('refuses another language than the locale\'s', function (): void {
    $world = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);
    $world->entries->withHead(World::entry(), VariantKey::of(new Locale('en')), new StoredHead(new AggregateVersion(1), new RevisionNumber(1), new RevisionNumber(1), null));

    expect(localizedPublishErrors($world->publishVariants(['shared' => [6, 4], 'da' => [5, 2], 'en' => [1, 1]])))->toBe(['validation_failed variants[2].variant'])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a variant at another version than the caller saw', function (): void {
    $world = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    expect(localizedPublishErrors($world->publishVariants(['shared' => [6, 4], 'da' => [4, 2]])))->toBe(['version_conflict -'])
        ->and($world->committer->pending)->toBe([]);
});

it('refuses the publish to an agent with agent_visibility_forbidden (invariant 18)', function (): void {
    $world = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    expect(localizedPublishErrors($world->publishVariants(['shared' => [6, 4], 'da' => [5, 2]], agent: true)))->toBe(['agent_visibility_forbidden -'])
        ->and($world->committer->pending)->toBe([]);
});

it('needs the publisher in the locale for the language and in every locale for the shared variant', function (): void {
    $daOnly = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]])->grant(World::ROOT, locales: ['da']);
    $everywhere = World::localized()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]])->grant(World::ROOT);

    expect(localizedPublishErrors($daOnly->publishVariants(['shared' => [6, 4], 'da' => [5, 2]])))->toBe(['unauthorized -'])
        ->and($everywhere->publishVariants(['shared' => [6, 4], 'da' => [5, 2]])->outcome())->toBe(Outcome::Committed);
});

it('lists on a dry run the placements the release of the shared variant makes visible, and commits nothing', function (): void {
    $world = World::localized()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Live, World::window(-2), false]]);

    $result = $world->publishVariants(['shared' => [6, 4], 'da' => [5, 2]], dryRun: true);
    $report = $result->dryRun;

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($report instanceof DryRunReport ? array_map(static fn (BecomesVisible $visible): string => $visible->placement->toString().' '.$visible->locale->value, $report->visible) : [])
        ->toBe([World::HOME_PLACEMENT.' da', World::AWAY_PLACEMENT.' da'])
        ->and($world->committer->pending)->toBe([]);
});

it('is exposed on every surface as entry.publish version 2, and takes each variant once', function (): void {
    $attributes = new ReflectionClass(PublishLocalizedEntryAction::class)->getAttributes(Action::class);
    $action = array_map(static fn (ReflectionAttribute $attribute): Action => $attribute->newInstance(), $attributes)[0];
    $shared = new VariantRelease(VariantKey::shared(), new AggregateVersion(6), new RevisionNumber(4));

    expect($action->handles)->toBe(PublishLocalizedEntry::class)
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])
        ->and(static fn (): PublishLocalizedEntry => new PublishLocalizedEntry(World::entry(), [$shared, $shared], World::placement(), new AggregateVersion(1), new Locale('da')))
        ->toThrow(InvalidArgumentException::class, 'given twice')
        ->and(static fn (): PublishLocalizedEntry => new PublishLocalizedEntry(World::entry(), [], World::placement(), new AggregateVersion(1), new Locale('da')))
        ->toThrow(InvalidArgumentException::class, 'at least the shared variant');
});

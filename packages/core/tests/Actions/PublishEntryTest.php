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
use Cbox\Cms\Contracts\Identity\GrantEffect;
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
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Publishing\PublishingActionWorld as World;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.publish's action in the command pipeline with fakes (GUARDRAILS 9, PRD 6.2, 6.4): it is
 * composite, so its one plan is the release of the revision it names followed by the home
 * placement's window in the locale, now or later, with the canonical move that window causes, and
 * a dry run lists every placement that becomes visible, those the release shows included. A type
 * with stages none composes only the placement. It is rejected for a placement the actor cannot
 * reach, one that is not the entry's home placement, a locale the placement lacks or is withdrawn
 * in, a revision the type cannot have or needs, an agent (invariant 18) and a call that changes
 * nothing, and it is version_conflict for a stale variant or placement and a read that went stale
 * before the commit.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function publishErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function publishSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof VariantReleased => sprintf('release %s %d', $mutation->type->toString(), $mutation->revision->value),
        $mutation instanceof PlacementWindowSet => sprintf('window %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->window instanceof TimeWindow ? ($mutation->window->from?->format('H:i') ?? 'open') : 'none'),
        $mutation instanceof PlacementCanonicalSet => sprintf('canonical %s %s %s', $mutation->placement->toString(), $mutation->locale->value, $mutation->canonical ? 'set' : 'cleared'),
        default => $mutation::class,
    }, $mutations);
}

/**
 * @return list<string> each placement a dry run lists as "<placement> <locale> <time it becomes visible>"
 */
function publishVisible(WriteResult $result): array
{
    $report = $result->dryRun;

    return $report instanceof DryRunReport
        ? array_map(static fn (BecomesVisible $visible): string => sprintf('%s %s %s', $visible->placement->toString(), $visible->locale->value, $visible->from->format('d H:i')), $report->visible)
        : [];
}

it('plans the release and the home placement live from now in one changeset, and reads the entry, the variant and the placements', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, false]]);

    $result = $world->publish();
    $pending = $world->committed();
    $variant = new VariantRef(World::entry(), VariantKey::shared());
    $slot = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.publish')
        ->and(publishSteps($pending->plan->mutations()))->toBe([
            'release '.NoteType::ID.' 4',
            'window '.World::HOME_PLACEMENT.' da 12:00',
            'canonical '.World::HOME_PLACEMENT.' da set',
        ])
        ->and($pending->reads->of(World::entry()))->toEqual(ReadVersion::at(World::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::at($variant, new AggregateVersion(6)))
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(1)))
        ->and($pending->reads->of($slot))->toEqual(ReadVersion::absent($slot));
});

it('schedules the home placement in the window the command gives', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    $world->publish(window: World::window(3, 48));
    [, $window] = $world->committed()->plan->mutations();

    expect(publishSteps($world->committed()->plan->mutations()))->toBe(['release '.NoteType::ID.' 4', 'window '.World::HOME_PLACEMENT.' da 15:00'])
        ->and($window)->toEqual(new PlacementWindowSet(World::placement(), new Locale('da'), World::window(3, 48)));
});

it('lists on a dry run every placement that becomes visible, those whose windows the release opens included, and commits nothing', function (): void {
    $world = new World()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, false], 'en' => [Visibility::Scheduled, World::window(5), false]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Live, World::window(-2), true]])
        ->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Live, World::window(-5, -1), false], 'en' => [Visibility::Hidden, null, true]]);

    $result = $world->publish(dryRun: true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and(publishVisible($result))->toBe([
            World::HOME_PLACEMENT.' da 10 12:00',
            World::HOME_PLACEMENT.' en 10 17:00',
            World::AWAY_PLACEMENT.' da 10 12:00',
        ])
        ->and(publishSteps($result->dryRun?->plan->mutations() ?? []))->toBe(['release '.NoteType::ID.' 4', 'window '.World::HOME_PLACEMENT.' da 12:00'])
        ->and($world->committer->pending)->toBe([]);
});

it('releases a new revision of content that is live already, and lists nothing on a dry run, because nothing new becomes visible', function (): void {
    $head = new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), new RevisionNumber(3));
    $world = new World($head)->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);

    $dryRun = $world->publish(dryRun: true);
    $world->publish();

    expect(publishVisible($dryRun))->toBe([])
        ->and(publishSteps($world->committed()->plan->mutations()))->toBe(['release '.NoteType::ID.' 4']);
});

it('moves a scheduled home placement to now and lists it from now', function (): void {
    $head = new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), new RevisionNumber(4));
    $world = new World($head)->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Scheduled, World::window(6), true]]);

    $dryRun = $world->publish(dryRun: true);
    $world->publish();

    expect(publishVisible($dryRun))->toBe([World::HOME_PLACEMENT.' da 10 12:00'])
        ->and(publishSteps($world->committed()->plan->mutations()))->toBe(['window '.World::HOME_PLACEMENT.' da 12:00']);
});

it('composes only the placement for a type with stages none, and refuses a revision for it', function (): void {
    $world = World::unstaged()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Live, World::window(-1), false]]);

    $dryRun = $world->publish(revision: null, version: 3, dryRun: true);
    $published = $world->publish(revision: null, version: 3);
    $withRevision = $world->publish(revision: 1, version: 3);

    expect($published->outcome())->toBe(Outcome::Committed)
        ->and(publishSteps($world->committed()->plan->mutations()))->toBe(['window '.World::HOME_PLACEMENT.' da 12:00'])
        ->and(publishVisible($dryRun))->toBe([World::HOME_PLACEMENT.' da 10 12:00'])
        ->and(publishErrors($withRevision))->toBe(['validation_failed revision'])
        ->and($withRevision->errors[0]->message)->toContain('stages none');
});

it('refuses a publish without a revision for a type with stages', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    $result = $world->publish(revision: null);

    expect(publishErrors($result))->toBe(['validation_failed revision'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a placement below a node the actor\'s grants do not reach as unauthorized', function (): void {
    $world = new World()->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Hidden, null, true]]);

    $result = $world->publish(placement: World::FAR_PLACEMENT);

    expect(publishErrors($result))->toBe(['unauthorized placement'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a release by an actor whose grants on the home do not hold in every locale, and allows the window alone (security review S-1)', function (string $grants): void {
    $grant = static fn (World $world): World => $grants === 'da only'
        ? $world->grant(World::HOME, locales: ['da'])
        : $world->grant(World::ROOT)->grant(World::HOME, GrantEffect::Deny, ['en']);
    $hidden = ['da' => [Visibility::Hidden, null, true], 'en' => [Visibility::Live, null, false]];
    $staged = $grant(new World()->place(World::HOME_PLACEMENT, World::HOME, $hidden));
    $released = $grant(new World(new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), new RevisionNumber(4)))->place(World::HOME_PLACEMENT, World::HOME, $hidden));
    $unstaged = $grant(World::unstaged()->place(World::HOME_PLACEMENT, World::HOME, $hidden));

    $release = $staged->publish();
    $windowOfReleased = $released->publish();
    $window = $unstaged->publish(revision: null, version: 3);

    expect(publishErrors($release))->toBe(['unauthorized -'])
        ->and($staged->committer->pending)->toBe([])
        ->and($windowOfReleased->outcome())->toBe(Outcome::Committed)
        ->and(publishSteps($released->committed()->plan->mutations()))->toBe(['window '.World::HOME_PLACEMENT.' da 12:00'])
        ->and($window->outcome())->toBe(Outcome::Committed)
        ->and(publishSteps($unstaged->committed()->plan->mutations()))->toBe(['window '.World::HOME_PLACEMENT.' da 12:00']);
})->with(['da only', 'deny en']);

it('rejects a placement that is not the entry\'s home placement, a locale it lacks and a locale it is withdrawn in', function (): void {
    $world = new World()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true], 'en' => [Visibility::Withdrawn, null, false]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Hidden, null, false]])
        ->place('01936f5e-8a2b-7c3d-9e4f-000000000654', World::HOME, ['da' => [Visibility::Hidden, null, true]], entry: World::OTHER_ENTRY);

    $away = $world->publish(placement: World::AWAY_PLACEMENT);
    $foreign = $world->publish(placement: '01936f5e-8a2b-7c3d-9e4f-000000000654');
    $lacking = $world->publish(locale: 'de');
    $withdrawn = $world->publish(locale: 'en');

    expect(publishErrors($away))->toBe(['validation_failed placement'])
        ->and($away->errors[0]->message)->toContain('home node')
        ->and(publishErrors($foreign))->toBe(['validation_failed placement'])
        ->and(publishErrors($lacking))->toBe(['validation_failed locale'])
        ->and(publishErrors($withdrawn))->toBe(['validation_failed locale'])
        ->and($withdrawn->errors[0]->message)->toContain('withdrawn')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an agent, which may not make content public', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]]);

    $result = $world->publish(agent: true);

    expect(publishErrors($result))->toBe(['agent_visibility_forbidden -'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a publish that changes nothing: the revision is released and the placement live', function (): void {
    $head = new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), new RevisionNumber(4));
    $world = new World($head)->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true]]);

    $now = $world->publish();
    $same = $world->publish(window: World::window(-2));

    expect(publishErrors($now))->toBe(['validation_failed -'])
        ->and($now->errors[0]->message)->toContain('changes nothing')
        ->and(publishErrors($same))->toBe(['validation_failed -'])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a variant or a placement at another version, and for a placement that does not exist', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]], version: 3);

    $variant = $world->publish(placementVersion: 3, version: 5);
    $placement = $world->publish(placementVersion: 2);
    $missing = $world->publish(placement: World::AWAY_PLACEMENT);

    expect(publishErrors($variant))->toBe(['version_conflict -'])
        ->and($variant->errors[0]->message)->toContain('variant:'.World::ENTRY.':shared')
        ->and(publishErrors($placement))->toBe(['version_conflict -'])
        ->and($placement->errors[0]->message)->toContain('placement:'.World::HOME_PLACEMENT)
        ->and(publishErrors($missing))->toBe(['version_conflict -'])
        ->and($missing->errors[0]->message)->toContain('placement:'.World::AWAY_PLACEMENT)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another placement of the entry in the locale changed before the commit', function (): void {
    $world = new World()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, true]])
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Hidden, null, false]])
        ->commitWith(new VersionConflict(new StaleRead(World::placement(World::AWAY_PLACEMENT), new AggregateVersion(1), new AggregateVersion(2))));

    $result = $world->publish();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(publishErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toContain('placement:'.World::AWAY_PLACEMENT)
        ->and($world->committed()->reads->of(World::placement(World::AWAY_PLACEMENT)))->toEqual(ReadVersion::at(World::placement(World::AWAY_PLACEMENT), new AggregateVersion(1)));
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new World()->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Hidden, null, false]]);
    $world->publish();
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(PublishEntryAction::class)->getAttributes(Action::class),
    );

    expect($world->committed()->command->value)->toBe('entry.publish')
        ->and($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});

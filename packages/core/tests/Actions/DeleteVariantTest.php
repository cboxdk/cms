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
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWithdrawn;
use Cbox\Cms\Contracts\Plans\Mutations\VariantWithdrawn;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\DeleteVariantAction;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Tests\Publishing\PublishingActionWorld as World;
use ReflectionAttribute;
use ReflectionClass;

/*
 * variant.delete's action in the command pipeline with fakes (GUARDRAILS 9, PRD 6.2, 10.3): its
 * one plan withdraws a language's variant and every placement of the entry in that locale that is
 * not withdrawn already, whatever its state, on every site, the ones below nodes the actor's
 * regions do not reach included, because deleting a translation is decided on the entry's home in
 * the locale. The other locales' placements are left as they are. The shared variant, a type
 * without localization variants and a variant withdrawn already are validation_failed, a stale
 * variant is version_conflict, and the command is authorized in the variant's locale.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function deleteVariantErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * @param  list<Mutation>  $mutations
 * @return list<string>
 */
function deleteVariantSteps(array $mutations): array
{
    return array_map(static fn (Mutation $mutation): string => match (true) {
        $mutation instanceof VariantWithdrawn => 'withdraw variant '.$mutation->variant->value,
        $mutation instanceof PlacementWithdrawn => sprintf('withdraw %s %s', $mutation->placement->toString(), $mutation->locale->value),
        default => $mutation::class,
    }, $mutations);
}

/**
 * The localized world with placements of the entry in da and en: HOME's live and canonical in
 * both, FAR's hidden in da, outside the editor's regions, and AWAY's withdrawn in da.
 */
function deleteVariantWorld(): World
{
    return World::localized()
        ->place(World::HOME_PLACEMENT, World::HOME, ['da' => [Visibility::Live, World::window(-2), true], 'en' => [Visibility::Live, World::window(-2), true]], version: 3)
        ->place(World::FAR_PLACEMENT, World::FAR, ['da' => [Visibility::Hidden, null, false]], version: 2)
        ->place(World::AWAY_PLACEMENT, World::AWAY, ['da' => [Visibility::Withdrawn, null, false]]);
}

it('withdraws the variant and every placement of the entry in its locale that is not withdrawn, on every site, in one changeset', function (): void {
    $world = deleteVariantWorld();

    $result = $world->deleteVariant('da', 5);
    $pending = $world->committed();
    $variant = new VariantRef(World::entry(), VariantKey::of(new Locale('da')));
    $slot = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('variant.delete')
        ->and(deleteVariantSteps($pending->plan->mutations()))->toBe([
            'withdraw variant da',
            'withdraw '.World::HOME_PLACEMENT.' da',
            'withdraw '.World::FAR_PLACEMENT.' da',
        ])
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::at($variant, new AggregateVersion(5)))
        ->and($pending->reads->of(World::entry()))->toEqual(ReadVersion::at(World::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of(World::placement()))->toEqual(ReadVersion::at(World::placement(), new AggregateVersion(3)))
        ->and($pending->reads->of(World::placement(World::FAR_PLACEMENT)))->toEqual(ReadVersion::at(World::placement(World::FAR_PLACEMENT), new AggregateVersion(2)))
        ->and($pending->reads->of($slot))->toEqual(ReadVersion::at($slot, new AggregateVersion(1)));
});

it('withdraws only the variant of an entry without placements in the locale', function (): void {
    $world = World::localized();

    $world->deleteVariant('da', 5);

    expect(deleteVariantSteps($world->committed()->plan->mutations()))->toBe(['withdraw variant da']);
});

it('refuses the shared variant, a type without localization variants and a variant withdrawn already with validation_failed', function (): void {
    $shared = deleteVariantWorld();
    $note = new World;
    $withdrawn = World::localized();
    $withdrawn->entries->withHead(World::entry(), VariantKey::of(new Locale('da')), new StoredHead(new AggregateVersion(7), new RevisionNumber(2), new RevisionNumber(2), null, withdrawn: true));

    $ofShared = $shared->deleteVariant('shared', 6);
    $ofNote = $note->deleteVariant('shared', 6);
    $again = $withdrawn->deleteVariant('da', 7);

    expect(deleteVariantErrors($ofShared))->toBe(['validation_failed variant'])
        ->and($ofShared->errors[0]->message)->toContain('the shared variant holds the fields every language shares')
        ->and(deleteVariantErrors($ofNote))->toBe(['validation_failed variant'])
        ->and($ofNote->errors[0]->message)->toContain('has localization none')
        ->and(deleteVariantErrors($again))->toBe(['validation_failed variant'])
        ->and($again->errors[0]->message)->toContain('withdrawn already')
        ->and([$shared->committer->pending, $note->committer->pending, $withdrawn->committer->pending])->toBe([[], [], []]);
});

it('is version_conflict for a variant at another version, and for a variant the entry does not have', function (): void {
    $world = deleteVariantWorld();

    $stale = $world->deleteVariant('da', 4);
    $missing = $world->deleteVariant('de', 1);

    expect(deleteVariantErrors($stale))->toBe(['version_conflict -'])
        ->and($stale->errors[0]->message)->toContain('variant:'.World::ENTRY.':da')
        ->and(deleteVariantErrors($missing))->toBe(['version_conflict -'])
        ->and($world->committer->pending)->toBe([]);
});

it('is authorized at the entry\'s home in the variant\'s locale (PRD 5.10)', function (): void {
    $translator = deleteVariantWorld()->grant(World::ROOT, GrantEffect::Allow, ['en']);
    $danish = deleteVariantWorld()->grant(World::ROOT, GrantEffect::Allow, ['da']);

    $refused = $translator->deleteVariant('da', 5);
    $deleted = $danish->deleteVariant('da', 5);

    expect(deleteVariantErrors($refused))->toBe(['unauthorized -'])
        ->and($refused->errors[0]->message)->toContain('in the locale da')
        ->and($translator->committer->pending)->toBe([])
        ->and($deleted->outcome())->toBe(Outcome::Committed);
});

it('computes the plan on a dry run and commits nothing', function (): void {
    $world = deleteVariantWorld();

    $result = $world->deleteVariant('da', 5, dryRun: true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and(deleteVariantSteps($result->dryRun?->plan->mutations() ?? []))->toBe(['withdraw variant da', 'withdraw '.World::HOME_PLACEMENT.' da', 'withdraw '.World::FAR_PLACEMENT.' da'])
        ->and($world->committer->pending)->toBe([]);
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(DeleteVariantAction::class)->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});

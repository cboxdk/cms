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
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Entries\Actions\ReviseVariantsAction;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseVariantsAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantRevision;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LocalizedProbeType;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.revise version 2's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.4, 6.2,
 * 10.3): a revise of several variants reads the entry with the head of each variant in one lookup
 * and plans, per variant, the revision after its highest number and the head moved to it, every
 * revision before every move, in one changeset. A language's variant is authorized in its locale
 * and the shared variant in every locale, so a translator granted one language writes that
 * language alone. It is version_conflict when a variant is at another version than the caller saw,
 * when the entry does not have the variant, and when a variant changed before the commit.
 */

const REVISE_ROOT = '01936f5e-8a2b-7c3d-9e4f-0000000003a0';

/**
 * A world with the entry of the localized probe type at version 2, the head of its shared variant
 * at version 3 on draft revision 1 and of its da variant at version 5 on draft revision 2 with a
 * published revision 3 after it, and no en variant.
 */
function reviseVariantsWorld(): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [LocalizedProbeType::definition()];
    $world->validators = [new LocalizedProbeType];
    $world->entries
        ->withEntry(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(3), RevisionNumber::first(), RevisionNumber::first(), null))
        ->withHead(EntryActionWorld::entry(), VariantKey::of(new Locale('da')), new StoredHead(new AggregateVersion(5), new RevisionNumber(2), new RevisionNumber(3), new RevisionNumber(3)));

    return $world;
}

function reviseShared(int $rank): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('rank'), new IntegerValue($rank))));
}

/**
 * @return list<string>
 */
function reviseVariantsCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The fake authorizer deciding from a grant to the editor of a role with entry.revise on the root
 * above HOME, in the locales given, or every locale for null.
 *
 * @param  list<string>|null  $locales
 */
function reviseGrantedIn(EntryActionWorld $world, ?array $locales): FakeCommandAuthorizer
{
    $root = str_replace('-', '', REVISE_ROOT);
    $permissions = new FakePermissions([EntryActionWorld::HOME => new NodePath($root.'.'.str_replace('-', '', EntryActionWorld::HOME))]);
    $permissions->grant($world->editor, new Grant(
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003f1'),
        ClassificationAccess::Internal,
        new NodePath($root),
        GrantEffect::Allow,
        $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
    ), [new CommandName('entry.revise')]);

    return FakeCommandAuthorizer::granting($permissions);
}

it('plans a revision and a move per variant, every revision first, and reads the entry and each variant at its version', function (): void {
    $world = reviseVariantsWorld();

    $result = $world->reviseVariants(['da' => [5, EntryActionWorld::fields('Havnen åbner')], 'shared' => [3, reviseShared(7)]]);
    $pending = $world->committed();
    $mutations = $pending->plan->mutations();
    $da = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da')));
    $shared = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.revise')
        ->and($pending->version)->toBe(2)
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $mutations))->toBe([RevisionCreated::class, RevisionCreated::class, HeadMoved::class, HeadMoved::class])
        ->and($mutations[0] instanceof RevisionCreated ? [$mutations[0]->variant->value, $mutations[0]->revision->value] : [])->toBe(['da', 4])
        ->and($mutations[1] instanceof RevisionCreated ? [$mutations[1]->variant->value, $mutations[1]->revision->value, $mutations[1]->fields->equals(reviseShared(7))] : [])->toBe(['shared', 2, true])
        ->and($mutations[2] instanceof HeadMoved ? [$mutations[2]->variant->value, $mutations[2]->from?->value, $mutations[2]->to->value] : [])->toBe(['da', 2, 4])
        ->and($mutations[3] instanceof HeadMoved ? [$mutations[3]->variant->value, $mutations[3]->from?->value, $mutations[3]->to->value] : [])->toBe(['shared', 1, 2])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(5)))
        ->and($pending->reads->of($shared))->toEqual(ReadVersion::at($shared, new AggregateVersion(3)));
});

it('authorizes each language in its locale and the shared variant in every locale, so a translator granted de alone writes de alone', function (): void {
    $world = reviseVariantsWorld();
    $world->entries->withHead(EntryActionWorld::entry(), VariantKey::of(new Locale('de')), new StoredHead(new AggregateVersion(1), RevisionNumber::first(), RevisionNumber::first(), null));
    $world->authorizer = reviseGrantedIn($world, ['de']);

    $da = $world->reviseVariants(['da' => [5, EntryActionWorld::fields('Oversat')]]);
    $shared = $world->reviseVariants(['shared' => [3, reviseShared(1)]]);
    $both = $world->reviseVariants(['de' => [1, EntryActionWorld::fields('Übersetzt')], 'da' => [5, EntryActionWorld::fields('Oversat')]]);
    $de = $world->reviseVariants(['de' => [1, EntryActionWorld::fields('Übersetzt')]]);
    $world->authorizer = reviseGrantedIn($world, null);
    $everywhere = $world->reviseVariants(['da' => [5, EntryActionWorld::fields('Oversat')], 'shared' => [3, reviseShared(1)]]);

    $scope = new ReviseVariantsAggregates(EntryActionWorld::entry(), [
        new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da'))),
        new VariantRef(EntryActionWorld::entry(), VariantKey::shared()),
    ], new StoredVariants(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2), []))->authorizationScope();

    expect(reviseVariantsCodes($da))->toBe(['unauthorized'])
        ->and(reviseVariantsCodes($shared))->toBe(['unauthorized'])
        ->and(reviseVariantsCodes($both))->toBe(['unauthorized'])
        ->and($de->outcome())->toBe(Outcome::Committed)
        ->and($everywhere->outcome())->toBe(Outcome::Committed)
        ->and($scope->targets)->toEqual([new AuthorizationTarget(EntryActionWorld::home(), new Locale('da')), new AuthorizationTarget(EntryActionWorld::home())])
        ->and(count($world->committer->pending))->toBe(2);
});

it('is version_conflict for a variant at another version, a variant the entry does not have, and a variant that changed before the commit', function (): void {
    $world = reviseVariantsWorld();
    $da = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da')));

    $stale = $world->reviseVariants(['da' => [4, EntryActionWorld::fields('Sent')], 'shared' => [3, reviseShared(1)]]);
    $missing = $world->reviseVariants(['en' => [1, EntryActionWorld::fields('Late')]]);
    $world->commitWith(new VersionConflict(new StaleRead($da, new AggregateVersion(5), new AggregateVersion(6))));
    $changed = $world->reviseVariants(['da' => [5, EntryActionWorld::fields('Raced')]]);

    expect(reviseVariantsCodes($stale))->toBe(['version_conflict'])
        ->and($stale->errors[0]->message)->toContain('variant:'.EntryActionWorld::ENTRY.':da')
        ->and(reviseVariantsCodes($missing))->toBe(['version_conflict'])
        ->and(reviseVariantsCodes($changed))->toBe(['version_conflict']);
});

it('validates each variant against the fields it holds, and refuses a variant the type does not hold', function (): void {
    $world = reviseVariantsWorld();
    $world->entries
        ->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(1))
        ->withHead(EntryActionWorld::entry(), VariantKey::of(new Locale('da')), new StoredHead(new AggregateVersion(5), new RevisionNumber(2), new RevisionNumber(3), new RevisionNumber(3)));

    $noteInDanish = $world->reviseVariants(['da' => [5, EntryActionWorld::fields('Indkøb')]]);

    $localized = reviseVariantsWorld();
    $rankInDanish = $localized->reviseVariants(['da' => [5, EntryActionWorld::fields('Havnen', ['rank' => new IntegerValue(1)])]]);

    expect(reviseVariantsCodes($noteInDanish))->toBe(['validation_failed', 'validation_failed'])
        ->and($noteInDanish->errors[1]->message)->toContain('has no variant da')
        ->and(reviseVariantsCodes($rankInDanish))->toBe(['validation_failed', 'validation_unknown_field'])
        ->and($rankInDanish->errors[1]->path?->toString())->toBe('fields.rank');
});

it('is not a command without a variant or with a variant given twice', function (): void {
    $da = new VariantRevision(VariantKey::of(new Locale('da')), new AggregateVersion(1), new FieldValues);

    expect(static fn (): ReviseVariants => new ReviseVariants(EntryActionWorld::entry(), []))->toThrow(InvalidArgumentException::class, 'at least one variant')
        ->and(static fn (): ReviseVariants => new ReviseVariants(EntryActionWorld::entry(), [$da, $da]))->toThrow(InvalidArgumentException::class, 'given twice');
});

it('is exposed on every surface as entry.revise version 2', function (): void {
    $attributes = new ReflectionClass(ReviseVariantsAction::class)->getAttributes(Action::class);
    $action = array_map(static fn (ReflectionAttribute $attribute): Action => $attribute->newInstance(), $attributes)[0];

    expect($action->handles)->toBe(ReviseVariants::class)
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]);
});

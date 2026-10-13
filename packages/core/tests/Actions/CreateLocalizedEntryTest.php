<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\CreateLocalizedEntryAction;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LocalizedProbeType;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.create version 2's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.4, 6.2,
 * 10.1): a create of an entry with variants reads the entry and each variant as absent and the home
 * node at its version and plans the entry, the first revision of the shared variant and of each
 * language's variant, and each head on its revision, in one changeset. Each variant's fields are
 * validated against the fields that variant holds. It refuses a type with localization variants
 * without a language, and a language of a type without localization, and a locale given twice is
 * not a command at all.
 */

/**
 * A world whose catalog knows the localized probe type beside the note.
 */
function localizedCreateWorld(): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [LocalizedProbeType::definition()];
    $world->validators = [new LocalizedProbeType];

    return $world;
}

function localizedType(): TypeId
{
    return TypeId::fromString(LocalizedProbeType::ID);
}

/**
 * @return list<string>
 */
function createLocalizedCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

function localizedTitle(string $title): FieldValues
{
    return EntryActionWorld::fields($title);
}

it('plans the entry, the first revision of the shared variant and of each language\'s variant, and each head, reading them all as absent', function (): void {
    $world = localizedCreateWorld();
    $shared = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('rank'), new IntegerValue(2))));

    $result = $world->createLocalized($shared, ['da' => localizedTitle('Havnen'), 'en' => localizedTitle('The harbour')], localizedType());
    $pending = $world->committed();
    $mutations = $pending->plan->mutations();
    $da = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da')));
    $en = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('en')));
    $sharedRef = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.create')
        ->and($pending->version)->toBe(2)
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $mutations))->toBe([EntryCreated::class, RevisionCreated::class, RevisionCreated::class, RevisionCreated::class, HeadMoved::class, HeadMoved::class, HeadMoved::class])
        ->and(array_map(static fn (Mutation $mutation): string => $mutation instanceof RevisionCreated || $mutation instanceof HeadMoved ? $mutation->variant->value : 'entry', $mutations))->toBe(['entry', 'shared', 'da', 'en', 'shared', 'da', 'en'])
        ->and($mutations[1] instanceof RevisionCreated ? [$mutations[1]->revision->value, $mutations[1]->fields->equals($shared)] : [])->toBe([1, true])
        ->and($mutations[2] instanceof RevisionCreated && $mutations[2]->fields->equals(localizedTitle('Havnen')))->toBeTrue()
        ->and($mutations[6] instanceof HeadMoved ? [$mutations[6]->from, $mutations[6]->to->value] : [])->toBe([null, 1])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::absent(EntryActionWorld::entry()))
        ->and($pending->reads->of($sharedRef))->toEqual(ReadVersion::absent($sharedRef))
        ->and($pending->reads->of($da))->toEqual(ReadVersion::absent($da))
        ->and($pending->reads->of($en))->toEqual(ReadVersion::absent($en))
        ->and($pending->reads->of(EntryActionWorld::home()))->toEqual(ReadVersion::at(EntryActionWorld::home(), new AggregateVersion(1)));
});

it('validates each variant against the fields that variant holds', function (): void {
    $world = localizedCreateWorld();

    $titleInShared = $world->createLocalized(localizedTitle('Shared title'), ['da' => localizedTitle('Havnen')], localizedType());
    $languageWithoutTitle = $world->createLocalized(new FieldValues, ['da' => new FieldValues], localizedType());

    expect(createLocalizedCodes($titleInShared))->toBe(['validation_failed', 'validation_unknown_field'])
        ->and($titleInShared->errors[1]->path?->toString())->toBe('fields.title')
        ->and(createLocalizedCodes($languageWithoutTitle))->toBe(['validation_failed', 'validation_required'])
        ->and($languageWithoutTitle->errors[1]->path?->toString())->toBe('fields.title')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a type with localization variants without a language, and a language of a type without localization', function (): void {
    $world = localizedCreateWorld();

    $noLanguage = $world->createLocalized(new FieldValues, [], localizedType());
    $noteInDanish = $world->createLocalized(EntryActionWorld::fields('Groceries'), ['da' => EntryActionWorld::fields('Indkøb')]);
    $noteWithoutLanguages = $world->createLocalized(EntryActionWorld::fields('Groceries'), []);

    expect(createLocalizedCodes($noLanguage))->toBe(['validation_failed'])
        ->and($noLanguage->errors[0]->path?->toString())->toBe('locales')
        ->and($noLanguage->errors[0]->message)->toContain('at least one language')
        ->and(createLocalizedCodes($noteInDanish))->toBe(['validation_failed', 'validation_failed'])
        ->and($noteInDanish->errors[1]->message)->toContain('has no variant da')
        ->and($noteWithoutLanguages->outcome())->toBe(Outcome::Committed)
        ->and(count($world->committer->pending))->toBe(1);
});

it('is version_conflict for an entry whose variant exists, and rejects a home the actor cannot reach', function (): void {
    $world = localizedCreateWorld();
    $world->entries->withEntry(EntryActionWorld::entry(), localizedType(), EntryActionWorld::home(), new AggregateVersion(1));

    $exists = $world->createLocalized(new FieldValues, ['da' => localizedTitle('Havnen')], localizedType());
    $nowhere = new EntryActionWorld;
    $nowhere->types = [LocalizedProbeType::definition()];
    $nowhere->validators = [new LocalizedProbeType];
    $unreachable = $nowhere->createLocalized(new FieldValues, ['da' => localizedTitle('Havnen')], localizedType(), NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003a9'));

    expect(createLocalizedCodes($exists))->toBe(['version_conflict'])
        ->and(createLocalizedCodes($unreachable))->toBe(['validation_failed', 'validation_failed'])
        ->and($world->committer->pending)->toBe([]);
});

it('is not a command with a locale given twice', function (): void {
    expect(static fn (): CreateLocalizedEntry => new CreateLocalizedEntry(EntryActionWorld::entry(), localizedType(), EntryActionWorld::home(), new FieldValues, [
        new LocaleFields(new Locale('da'), localizedTitle('En')),
        new LocaleFields(new Locale('DA'), localizedTitle('To')),
    ]))->toThrow(InvalidArgumentException::class, 'given twice');
});

it('is exposed on every surface as entry.create version 2', function (): void {
    $attributes = new ReflectionClass(CreateLocalizedEntryAction::class)->getAttributes(Action::class);
    $action = array_map(static fn (ReflectionAttribute $attribute): Action => $attribute->newInstance(), $attributes)[0];

    expect($action->handles)->toBe(CreateLocalizedEntry::class)
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])
        ->and(new CreateLocalizedEntry(EntryActionWorld::entry(), localizedType(), EntryActionWorld::home(), new FieldValues, [])->variants())->toEqual([new VariantRef(EntryActionWorld::entry(), VariantKey::shared())]);
});

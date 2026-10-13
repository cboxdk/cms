<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\LocalizedEntries;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Support\Facades\DB;

/*
 * The entry commands of version 2 through the real command pipeline on Postgres (PRD 5.4, 5.6, 6.4,
 * 10.1, 10.3, invariants 11 and 18) on the workbench's app:fixture_notice, whose localization is
 * variants: entry.create writes the shared variant and a variant per language in one changeset,
 * each language's draft row holding the shared fields; entry.revise revises several variants, each
 * at its own version, in one changeset; variant.release releases any variant, and a language's
 * released row exists once both it and the shared variant are released. A translator granted one
 * language alone writes that language alone, an agent's release stays refused, and two concurrent
 * saves of several variants give one commit, one version_conflict and one changeset.
 *
 * entry.publish version 2 releases what a locale needs, the language's variant and the shared one,
 * and puts the home placement live, in one changeset, and an agent may not publish (invariant 18).
 * variant.create_from creates a language's variant from another's revision and records the
 * derivation translated_from, which a later release of the source with other content marks stale
 * (PRD 10.3).
 */

const VARIANT_TABLE = 'app__fixture_notice';

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * @return list<string>
 */
function variantErrorCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * A column's value as text: a string or an integer as it is, anything else as an empty string.
 */
function variantText(mixed $value): string
{
    return is_string($value) || is_int($value) ? (string) $value : '';
}

/**
 * The rows of the notice table of the entry as "<locale> <stage> <title> <published on> <priority>", sorted.
 *
 * @return list<string>
 */
function variantRows(string $entry = EntryWorld::ENTRY): array
{
    $rows = [];

    foreach (StorageTables::superuser()->table(VARIANT_TABLE)->where('cms_entry_id', $entry)->orderBy('cms_locale')->orderBy('cms_stage')->get() as $row) {
        $rows[] = implode(' ', array_map(variantText(...), [$row->cms_locale, $row->cms_stage, $row->fixture_title, $row->fixture_published_on, $row->fixture_priority]));
    }

    return $rows;
}

/**
 * The heads of the entry as "<variant> <release state> <version>", sorted by variant.
 *
 * @return list<string>
 */
function variantHeads(string $entry = EntryWorld::ENTRY): array
{
    $heads = [];

    foreach (StorageTables::superuser()->table('variant_heads')->where('entry_id', $entry)->orderBy('variant')->get() as $head) {
        $heads[] = implode(' ', array_map(variantText(...), [$head->variant, $head->release_state, $head->version]));
    }

    return $heads;
}

function variantChangesets(string $command): int
{
    return StorageTables::superuser()->table('changesets')->where('command', $command)->count();
}

/**
 * A world with the notice created in da and en through entry.create version 2.
 */
function variantWorld(?string $connection = null, int $seed = 1): EntryWorld
{
    $world = new EntryWorld($connection, seed: $seed);
    $type = EntryWorld::type(LocalizedEntries::NOTICE);
    $result = $world->createLocalized($type->id, LocalizedEntries::shared('2026-03-09', 3), [
        'da' => LocalizedEntries::translated('Havnen åbner'),
        'en' => LocalizedEntries::translated('The harbour opens'),
    ]);

    expect($result->outcome())->toBe(Outcome::Committed);

    return $world;
}

it('creates an entry with its shared variant and its languages in one changeset, each language\'s draft row holding the shared fields', function (): void {
    variantWorld();

    $superuser = StorageTables::superuser();

    expect(variantChangesets('entry.create'))->toBe(1)
        ->and(variantHeads())->toBe(['da unreleased 1', 'en unreleased 1', 'shared unreleased 1'])
        ->and($superuser->table('revisions')->where('entry_id', EntryWorld::ENTRY)->orderBy('variant')->pluck('rev_no', 'variant')->all())->toBe(['da' => 1, 'en' => 1, 'shared' => 1])
        ->and(variantRows())->toBe(['da draft Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-09 3'])
        ->and($superuser->table('events')->where('type', 'variant.revised')->count())->toBe(3)
        ->and($superuser->table('events')->where('type', 'entry.created')->count())->toBe(1);
});

it('refuses a type with localization variants without a language, and a language of a type without localization', function (): void {
    $world = new EntryWorld;
    $notice = EntryWorld::type(LocalizedEntries::NOTICE);
    $article = EntryWorld::type(EntryWorld::ARTICLE);

    $noLanguage = $world->createLocalized($notice->id, LocalizedEntries::shared('2026-03-09', 3), [], 'create-no-language');
    $localizedArticle = $world->createLocalized($article->id, EntryFields::article(), ['da' => new FieldValues], 'create-localized-article');

    expect(variantErrorCodes($noLanguage))->toBe(['validation_failed'])
        ->and($noLanguage->errors[0]->path?->toString())->toBe('locales')
        ->and(variantErrorCodes($localizedArticle))->toBe(['validation_failed', 'validation_failed'])
        ->and($localizedArticle->errors[1]->message)->toContain('has no variant da')
        ->and(StorageTables::superuser()->table('entries')->count())->toBe(0);
});

it('revises a language and the shared variant in one changeset with a revision in each (PRD 10.3)', function (): void {
    $world = variantWorld();

    $result = $world->reviseVariants([
        'da' => [1, LocalizedEntries::translated('Havnen åbner fredag', 'Kom og se')],
        'shared' => [1, LocalizedEntries::shared('2026-03-11', 2)],
    ]);

    $superuser = StorageTables::superuser();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(variantChangesets('entry.revise'))->toBe(1)
        ->and(variantHeads())->toBe(['da unreleased 2', 'en unreleased 1', 'shared unreleased 2'])
        ->and($superuser->table('revisions')->where('entry_id', EntryWorld::ENTRY)->where('rev_no', 2)->orderBy('variant')->pluck('variant')->all())->toBe(['da', 'shared'])
        ->and(variantRows())->toBe(['da draft Havnen åbner fredag 2026-03-11 2', 'en draft The harbour opens 2026-03-11 2'])
        ->and($superuser->table('events')->where('type', 'variant.revised')->where('aggregate_version', 2)->count())->toBe(2);
});

it('rejects a revise of a variant at a stale version, and of a variant the entry does not have, with version_conflict', function (): void {
    $world = variantWorld();
    $before = variantRows();

    $stale = $world->reviseVariants(['da' => [2, LocalizedEntries::translated('Sent')], 'shared' => [1, LocalizedEntries::shared('2026-03-12', 1)]], 'revise-stale');
    $missing = $world->reviseVariants(['de' => [1, LocalizedEntries::translated('Spät')]], 'revise-missing');

    expect(variantErrorCodes($stale))->toBe(['version_conflict'])
        ->and($stale->errors[0]->message)->toContain('variant:'.EntryWorld::ENTRY.':da')
        ->and(variantErrorCodes($missing))->toBe(['version_conflict'])
        ->and(variantChangesets('entry.revise'))->toBe(0)
        ->and(variantRows())->toBe($before);
});

it('lets a translator granted de alone write de, and neither da nor the shared variant (PRD 5.10, 10.3)', function (): void {
    $editor = new EntryWorld(seed: 1);
    $type = EntryWorld::type(LocalizedEntries::NOTICE);
    $editor->createLocalized($type->id, LocalizedEntries::shared('2026-03-09', 3), [
        'da' => LocalizedEntries::translated('Havnen åbner'),
        'de' => LocalizedEntries::translated('Der Hafen öffnet'),
    ]);
    $translator = new EntryWorld(seed: 2)->granted('translator', ['entry.revise', 'variant.release'], ['de']);

    $da = $translator->reviseVariants(['da' => [1, LocalizedEntries::translated('Oversat')]], 'translate-da');
    $shared = $translator->reviseVariants(['shared' => [1, LocalizedEntries::shared('2026-03-10', 1)]], 'translate-shared');
    $both = $translator->reviseVariants(['de' => [1, LocalizedEntries::translated('Übersetzt')], 'da' => [1, LocalizedEntries::translated('Oversat')]], 'translate-both');
    $de = $translator->reviseVariants(['de' => [1, LocalizedEntries::translated('Der Hafen öffnet am Freitag')]], 'translate-de');
    $releaseDa = $translator->releaseVariant('da', 1, 1, 'release-da');
    $releaseDe = $translator->releaseVariant('de', 2, 2, 'release-de');

    expect(variantErrorCodes($da))->toBe(['unauthorized'])
        ->and($da->errors[0]->message)->toContain('in the locale da')
        ->and(variantErrorCodes($shared))->toBe(['unauthorized'])
        ->and(variantErrorCodes($both))->toBe(['unauthorized'])
        ->and($de->outcome())->toBe(Outcome::Committed)
        ->and(variantErrorCodes($releaseDa))->toBe(['unauthorized'])
        ->and($releaseDe->outcome())->toBe(Outcome::Committed)
        ->and(variantHeads())->toBe(['da unreleased 1', 'de released 3', 'shared unreleased 1'])
        ->and(variantRows())->toBe(['da draft Havnen åbner 2026-03-09 3', 'de draft Der Hafen öffnet am Freitag 2026-03-09 3']);
});

it('refuses a release of a language to an agent with agent_visibility_forbidden (invariant 18)', function (): void {
    $world = variantWorld();
    $world->credential = IssuerKind::Agent;

    $result = $world->releaseVariant('da', 1, 1);

    expect(variantErrorCodes($result))->toBe(['agent_visibility_forbidden'])
        ->and(variantHeads())->toBe(['da unreleased 1', 'en unreleased 1', 'shared unreleased 1'])
        ->and(StorageTables::superuser()->table('release_log')->count())->toBe(0);
});

it('releases each variant on its own, and writes a language\'s released row once both it and the shared variant are released', function (): void {
    $world = variantWorld();

    $da = $world->releaseVariant('da', 1, 1, 'release-da');
    $rowsAfterDa = variantRows();
    $shared = $world->releaseVariant('shared', 1, 1, 'release-shared');
    $rowsAfterShared = variantRows();
    $revised = $world->reviseVariants(['shared' => [2, LocalizedEntries::shared('2026-03-12', 5)]], 'revise-shared');
    $rowsAfterRevise = variantRows();
    $again = $world->releaseVariant('shared', 3, 3, 'release-shared-again');
    $en = $world->releaseVariant('en', 1, 1, 'release-en');

    $superuser = StorageTables::superuser();

    expect([$da->outcome(), $shared->outcome(), $revised->outcome(), $again->outcome(), $en->outcome()])->toBe(array_fill(0, 5, Outcome::Committed))
        ->and($rowsAfterDa)->toBe(['da draft Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-09 3'])
        ->and($rowsAfterShared)->toBe(['da released Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-09 3'])
        ->and($rowsAfterRevise)->toBe(['da draft Havnen åbner 2026-03-12 5', 'da released Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-12 5'])
        ->and(variantRows())->toBe(['da released Havnen åbner 2026-03-12 5', 'en released The harbour opens 2026-03-12 5'])
        ->and(variantHeads())->toBe(['da released 2', 'en released 2', 'shared released 4'])
        ->and($superuser->table('release_log')->where('entry_id', EntryWorld::ENTRY)->orderBy('variant')->pluck('variant')->all())->toBe(['da', 'en', 'shared', 'shared'])
        ->and($superuser->table('events')->where('type', 'variant.released')->count())->toBe(4);
});

it('commits one of two concurrent revises of several variants and rejects the other with version_conflict', function (): void {
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $one = variantWorld($first->getName(), seed: 1);
    $other = new EntryWorld($second->getName(), seed: 2);

    $winner = null;
    $one->meanwhile = static function () use ($other, &$winner): void {
        $winner = $other->reviseVariants(['da' => [1, LocalizedEntries::translated('Den anden')], 'shared' => [1, LocalizedEntries::shared('2026-03-11', 4)]], 'revise-second');
    };
    $loser = $one->reviseVariants(['da' => [1, LocalizedEntries::translated('Den første')], 'shared' => [1, LocalizedEntries::shared('2026-03-10', 1)]], 'revise-first');

    expect($winner)->toBeInstanceOf(WriteResult::class)
        ->and($winner?->outcome())->toBe(Outcome::Committed)
        ->and($loser->outcome())->toBe(Outcome::Rejected)
        ->and(variantErrorCodes($loser))->toBe(['version_conflict', 'version_conflict'])
        ->and(variantChangesets('entry.revise'))->toBe(1)
        ->and(variantHeads())->toBe(['da unreleased 2', 'en unreleased 1', 'shared unreleased 2'])
        ->and(variantRows())->toBe(['da draft Den anden 2026-03-11 4', 'en draft The harbour opens 2026-03-11 4']);
});

it('revises one language with the same statements whether the entry has 2 or 6 languages', function (): void {
    $counts = [];

    foreach ([2, 6] as $languages) {
        $world = new EntryWorld(seed: $languages);
        $type = EntryWorld::type(LocalizedEntries::NOTICE);
        $entry = EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 7000 + $languages));
        $locales = [];

        foreach (array_slice(['da', 'en', 'de', 'fr', 'sv', 'nb'], 0, $languages) as $locale) {
            $locales[$locale] = LocalizedEntries::translated('Title '.$locale);
        }

        $world->createLocalized($type->id, LocalizedEntries::shared('2026-03-09', 3), $locales, 'create-'.$languages, $entry);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $result = $world->reviseVariants(['da' => [1, LocalizedEntries::translated('Counted')]], 'revise-'.$languages, $entry);
        $connection->disableQueryLog();
        $counts[$languages] = [$result->outcome(), count($connection->getQueryLog())];
        $connection->flushQueryLog();
    }

    expect($counts[2][0])->toBe(Outcome::Committed)
        ->and($counts[6][0])->toBe(Outcome::Committed)
        ->and($counts[6][1])->toBe($counts[2][1]);
});

const VARIANT_PLACEMENT = '0192a0c0-0000-7000-8000-0000000007c1';

/**
 * The derivations of the entry as "<variant> <rev_no> <kind> <source variant> <source rev_no> <stale or current>", sorted by variant.
 *
 * @return list<string>
 */
function variantDerivations(string $entry = EntryWorld::ENTRY): array
{
    $derivations = [];

    foreach (StorageTables::superuser()->table('derivations')->where('entry_id', $entry)->orderBy('variant')->get() as $row) {
        $derivations[] = implode(' ', [
            ...array_map(variantText(...), [$row->variant, $row->rev_no, $row->kind, $row->source_variant, $row->source_rev_no]),
            $row->stale_changeset_id === null ? 'current' : 'stale',
        ]);
    }

    return $derivations;
}

/**
 * The placement structure, a publishing world whose actor reaches north, and the notice created
 * in da and en homed on north's section and placed there in da, each through the pipeline.
 *
 * @return array{PublishingWorld, PlacementStructure}
 */
function publishingVariantWorld(): array
{
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->northSection], seed: 7);
    $entry = EntryWorld::entry();

    $created = $world->createLocalized($entry, LocalizedEntries::NOTICE, LocalizedEntries::shared('2026-03-09', 3), [
        'da' => LocalizedEntries::translated('Havnen åbner'),
        'en' => LocalizedEntries::translated('The harbour opens'),
    ], $structure->northSection, 'create-notice');
    $placed = $world->place(PlacementId::fromString(VARIANT_PLACEMENT), $entry, $structure->northSection, $structure->north, 'havnen', 'place-notice');

    expect([$created->outcome(), $placed->outcome()])->toBe([Outcome::Committed, Outcome::Committed]);

    return [$world, $structure];
}

/**
 * The visibility of the notice's placement in da.
 */
function variantPlacementVisibility(): string
{
    return variantText(StorageTables::superuser()->table('placement_locales')->where('placement_id', VARIANT_PLACEMENT)->where('locale', 'da')->where('stage', 'released')->value('visibility'));
}

it('publishes a language and the shared variant with the home placement live in one changeset (entry.publish version 2)', function (): void {
    [$world] = publishingVariantWorld();

    $result = $world->publishVariants(EntryWorld::entry(), ['shared' => [1, 1], 'da' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-v2');
    $superuser = StorageTables::superuser();
    $changeset = $result->receipt->changesetId?->toString();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(variantChangesets('entry.publish'))->toBe(1)
        ->and(variantHeads())->toBe(['da released 2', 'en unreleased 1', 'shared released 2'])
        ->and(variantRows())->toBe(['da released Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-09 3'])
        ->and(variantPlacementVisibility())->toBe('live')
        ->and($superuser->table('release_log')->where('entry_id', EntryWorld::ENTRY)->orderBy('variant')->pluck('changeset_id')->all())->toBe([$changeset, $changeset])
        ->and($superuser->table('events')->where('type', 'variant.released')->count())->toBe(2)
        ->and($superuser->table('events')->where('type', 'placement.visibility_changed')->count())->toBe(1);
});

it('releases only what the locale still needs: a language beside a shared variant released before', function (): void {
    [$world] = publishingVariantWorld();
    $shared = $world->releaseVariant(EntryWorld::entry(), 'shared', 1, 1, 'release-shared');

    // The shared variant's released revision is its published copy, number 2, which entry.get gives.
    $result = $world->publishVariants(EntryWorld::entry(), ['shared' => [2, 2], 'en' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-en', 'en');
    $da = $world->publishVariants(EntryWorld::entry(), ['shared' => [2, 2], 'da' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-da');
    $superuser = StorageTables::superuser();

    expect($shared->outcome())->toBe(Outcome::Committed)
        ->and(variantErrorCodes($result))->toBe(['validation_failed'])
        ->and($result->errors[0]->path?->toString())->toBe('locale')
        ->and($da->outcome())->toBe(Outcome::Committed)
        ->and(variantHeads())->toBe(['da released 2', 'en unreleased 1', 'shared released 2'])
        ->and($superuser->table('release_log')->where('entry_id', EntryWorld::ENTRY)->orderBy('variant')->pluck('variant')->all())->toBe(['da', 'shared'])
        ->and(variantPlacementVisibility())->toBe('live');
});

it('refuses a publish of version 2 without the locale\'s variant, or with another language\'s', function (): void {
    [$world] = publishingVariantWorld();

    $withoutDa = $world->publishVariants(EntryWorld::entry(), ['shared' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-no-da');
    $withEn = $world->publishVariants(EntryWorld::entry(), ['shared' => [1, 1], 'da' => [1, 1], 'en' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-with-en');

    expect(variantErrorCodes($withoutDa))->toBe(['validation_failed'])
        ->and($withoutDa->errors[0]->path?->toString())->toBe('variants')
        ->and(variantErrorCodes($withEn))->toBe(['validation_failed'])
        ->and($withEn->errors[0]->path?->toString())->toBe('variants[2].variant')
        ->and(variantChangesets('entry.publish'))->toBe(0)
        ->and(variantHeads())->toBe(['da unreleased 1', 'en unreleased 1', 'shared unreleased 1']);
});

it('refuses a publish of version 2 to an agent with agent_visibility_forbidden (invariant 18)', function (): void {
    [$world] = publishingVariantWorld();

    $result = $world->publishVariants(EntryWorld::entry(), ['shared' => [1, 1], 'da' => [1, 1]], PlacementId::fromString(VARIANT_PLACEMENT), 1, 'publish-agent', agent: true);

    expect(variantErrorCodes($result))->toBe(['agent_visibility_forbidden'])
        ->and(variantChangesets('entry.publish'))->toBe(0)
        ->and(variantHeads())->toBe(['da unreleased 1', 'en unreleased 1', 'shared unreleased 1'])
        ->and(variantPlacementVisibility())->toBe('hidden')
        ->and(StorageTables::superuser()->table('release_log')->count())->toBe(0);
});

it('creates a language\'s variant from another\'s revision and records translated_from (PRD 10.3)', function (): void {
    $world = variantWorld();

    $result = $world->createFrom('de', 'da', 1, 1);
    $superuser = StorageTables::superuser();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(variantChangesets('variant.create_from'))->toBe(1)
        ->and(variantHeads())->toBe(['da unreleased 1', 'de unreleased 1', 'en unreleased 1', 'shared unreleased 1'])
        ->and(variantRows())->toBe(['da draft Havnen åbner 2026-03-09 3', 'de draft Havnen åbner 2026-03-09 3', 'en draft The harbour opens 2026-03-09 3'])
        ->and(variantDerivations())->toBe(['de 1 translated_from da 1 current'])
        ->and($superuser->table('derivations')->value('changeset_id'))->toBe($result->receipt->changesetId?->toString())
        ->and($superuser->table('events')->where('type', 'variant.revised')->where('aggregate_id', 'like', '%:de')->count())->toBe(1);
});

it('refuses a variant the entry has, a revision the source does not have, and the shared variant as either side', function (): void {
    $world = variantWorld();

    $existing = $world->createFrom('en', 'da', 1, 1, 'create-existing');
    $missing = $world->createFrom('de', 'da', 4, 1, 'create-missing');
    $fromShared = $world->createFrom('de', 'shared', 1, 1, 'create-from-shared');
    $toShared = $world->createFrom('shared', 'da', 1, 1, 'create-shared');

    expect(variantErrorCodes($existing))->toBe(['version_conflict'])
        ->and(variantErrorCodes($missing))->toBe(['validation_failed'])
        ->and($missing->errors[0]->path?->toString())->toBe('revision')
        ->and(variantErrorCodes($fromShared))->toBe(['validation_failed'])
        ->and($fromShared->errors[0]->path?->toString())->toBe('source')
        ->and(variantErrorCodes($toShared))->toBe(['version_conflict'])
        ->and(variantChangesets('variant.create_from'))->toBe(0)
        ->and(variantDerivations())->toBe([]);
});

it('lets a translator granted de alone create de from da, and not fr', function (): void {
    variantWorld();
    $translator = new EntryWorld(seed: 2)->granted('translator', ['variant.create_from'], ['de']);

    $de = $translator->createFrom('de', 'da', 1, 1, 'translate-de');
    $fr = $translator->createFrom('fr', 'da', 1, 1, 'translate-fr');

    expect($de->outcome())->toBe(Outcome::Committed)
        ->and(variantErrorCodes($fr))->toBe(['unauthorized'])
        ->and(variantDerivations())->toBe(['de 1 translated_from da 1 current']);
});

it('marks a translation stale once its source is released with other content, and not by the release of what it was made from', function (): void {
    $world = variantWorld();
    $world->createFrom('de', 'da', 1, 1);

    $first = $world->releaseVariant('da', 1, 1, 'release-da');
    $afterFirst = variantDerivations();
    $revised = $world->reviseVariants(['da' => [2, LocalizedEntries::translated('Havnen åbner fredag')]], 'revise-da');
    $again = $world->releaseVariant('da', 3, 3, 'release-da-again');
    $staleBy = StorageTables::superuser()->table('derivations')->where('variant', 'de')->value('stale_changeset_id');

    expect([$first->outcome(), $revised->outcome(), $again->outcome()])->toBe(array_fill(0, 3, Outcome::Committed))
        ->and($afterFirst)->toBe(['de 1 translated_from da 1 current'])
        ->and(variantDerivations())->toBe(['de 1 translated_from da 1 stale'])
        ->and($staleBy)->toBe($again->receipt->changesetId?->toString());
});

it('forces row level security on the derivations and every partition of them, and lets the app role update only stale_changeset_id', function (): void {
    $world = variantWorld();
    $world->createFrom('de', 'da', 1, 1);
    $owner = DB::connection('pgsql_owner');
    $app = DB::connection();
    $tables = StorageTables::texts($owner, "select c.relname::text as value from pg_class c where c.relname = 'derivations' or c.oid in (select inhrelid from pg_inherits where inhparent = 'derivations'::regclass) order by 1");

    expect($tables)->toContain('derivations', 'derivations_p202603')
        ->and(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname = any (?::text[]) order by 1", ['{'.implode(',', $tables).'}']))
        ->toBe(array_map(static fn (string $table): string => $table.' true true', $tables))
        ->and(StorageTables::texts($owner, "select policyname::text as value from pg_policies where tablename = 'derivations'"))->toBe(['derivations_actor'])
        ->and($app->table('derivations')->count())->toBe(0);

    foreach ($tables as $table) {
        foreach (['SELECT' => true, 'INSERT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false] as $privilege => $held) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe($held, "{$privilege} on {$table}");
        }

        foreach (['stale_changeset_id' => true, 'source_rev_no' => false, 'variant' => false] as $column => $held) {
            expect($app->scalar("select has_column_privilege(current_user, ?::regclass, ?, 'UPDATE')", [$table, $column]))->toBe($held, "UPDATE of {$column} on {$table}");
        }
    }
});

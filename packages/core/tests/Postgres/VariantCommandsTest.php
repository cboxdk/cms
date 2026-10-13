<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\LocalizedEntries;
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

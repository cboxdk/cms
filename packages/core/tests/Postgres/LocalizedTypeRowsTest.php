<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\TypeTables\EntryRecord;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\RecordPage;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\TypeRows;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\LocalizedEntries;
use Illuminate\Support\Facades\DB;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureNotice\AppFixtureNoticeQuery;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureNotice\AppFixtureNoticeSortField;
use Workbench\App\Cms\Generated\Records\AppFixtureNotice\AppFixtureNoticeRecord;

/*
 * The type table of a type with localization variants on real Postgres (PRD 10.1, 11.6,
 * GUARDRAILS 4.1), the workbench's app:fixture_notice: TypeRows writes one row per language of an
 * entry, each with the language's fields and the shared variant's fields in it, and a write,
 * release or unrelease of the shared variant rewrites the shared columns of every language's row
 * of the entry in the same statements whether the entry has 2 or 6 languages. The reader behind
 * the generated query builder pages the rows of one language, and refuses to page without one.
 */

const LOCALIZED_TWO = ['da', 'en'];

const LOCALIZED_SIX = ['da', 'en', 'de', 'sv', 'nb', 'fi'];

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * Every row of the notice table as locale, stage, title, published_on and priority, in key order.
 *
 * @return list<string>
 */
function noticeRows(): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        'select cms_locale || \' \' || cms_stage || \' \' || fixture_title || \' \' || fixture_published_on::text || \' \' || coalesce(fixture_priority::text, \'-\') as value from app__fixture_notice order by cms_entry_id, cms_locale, cms_stage',
    );
}

/**
 * Writes an entry released in every one of the languages, with equal drafts, and the shared
 * variant released at the first of March with priority 1.
 *
 * @param  list<string>  $locales
 */
function noticeReleasedIn(int $number, array $locales): void
{
    $variants = [];

    foreach ($locales as $locale) {
        $variants[$locale] = [LocalizedEntries::translated('Title '.$locale), LocalizedEntries::translated('Title '.$locale)];
    }

    LocalizedEntries::notice()->write(LocalizedEntries::entry($number), EntryWorld::home(), LocalizedEntries::shared('2026-03-01', 1), LocalizedEntries::shared('2026-03-01', 1), $variants);
}

/**
 * The rows of the two entries, the first in LOCALIZED_TWO and the second in LOCALIZED_SIX, each
 * language with a row per stage given, "Title <locale>" with the date and priority of the stage.
 *
 * @param  array<string, array{string, int}>  $stages  the date and priority of each stage's row
 * @return list<string>
 */
function noticeExpected(array $stages): array
{
    $rows = [];

    foreach ([LOCALIZED_TWO, LOCALIZED_SIX] as $locales) {
        $sorted = $locales;
        sort($sorted, SORT_STRING);

        foreach ($sorted as $locale) {
            foreach ($stages as $stage => [$date, $priority]) {
                $rows[] = sprintf('%s %s Title %s %s %d', $locale, $stage, $locale, $date, $priority);
            }
        }
    }

    return $rows;
}

/**
 * The page in a read transaction of the app role with the staff member's context set, which
 * reaches the whole tree, as the query pipeline reads it.
 *
 * @template T of object
 *
 * @param  callable(AccessContext): RecordPage<T>  $read
 * @return RecordPage<T>
 */
function noticePage(EntryWorld $world, callable $read): RecordPage
{
    $access = new AccessContext(
        new ActorPrincipal($world->actor, [], IssuerKind::Service, ClassificationAccess::Internal),
        [new AccessRegion(new NodePath(StorageTables::label(EntryWorld::ROOT)))],
        ClassificationAccess::Internal,
    );

    return DB::connection()->transaction(static function () use ($read, $access): RecordPage {
        app(ActorContext::class)->set($access);

        return $read($access);
    });
}

it('writes one row per language with the shared fields in each, and none of the shared variant', function (): void {
    LocalizedEntries::notice()->write(
        LocalizedEntries::entry(1),
        EntryWorld::home(),
        LocalizedEntries::shared('2026-03-03', 3),
        LocalizedEntries::shared('2026-03-02', 2),
        [
            'da' => [LocalizedEntries::translated('Havnen'), LocalizedEntries::translated('Havnen')],
            'en' => [LocalizedEntries::translated('The harbour, revised'), LocalizedEntries::translated('The harbour')],
            'de' => [LocalizedEntries::translated('Der Hafen'), null],
        ],
    );

    expect(noticeRows())->toBe([
        'da draft Havnen 2026-03-03 3',
        'da released Havnen 2026-03-02 2',
        'de draft Der Hafen 2026-03-03 3',
        'en draft The harbour, revised 2026-03-03 3',
        'en released The harbour 2026-03-02 2',
    ]);
});

it('rewrites the shared columns of every language\'s row when the shared variant is written, released or unreleased, in the same statements for 2 and 6 languages (GUARDRAILS 4.1)', function (): void {
    noticeReleasedIn(1, LOCALIZED_TWO);
    noticeReleasedIn(2, LOCALIZED_SIX);
    $notices = LocalizedEntries::notice();
    $rows = new TypeRows(StorageTables::superuser());
    $two = LocalizedEntries::entry(1);
    $six = LocalizedEntries::entry(2);
    $counter = new class
    {
        public bool $counting = false;

        public int $count = 0;
    };
    StorageTables::superuser()->listen(static function () use ($counter): void {
        if ($counter->counting) {
            $counter->count++;
        }
    });
    $statements = static function (callable $work) use ($counter): int {
        $counter->count = 0;
        $counter->counting = true;
        $work();
        $counter->counting = false;

        return $counter->count;
    };

    $drafted = [
        $statements(static fn () => $rows->write($notices->type, $two, VariantKey::shared(), LocalizedEntries::shared('2026-03-05', 5))),
        $statements(static fn () => $rows->write($notices->type, $six, VariantKey::shared(), LocalizedEntries::shared('2026-03-05', 5))),
    ];
    $afterDraft = noticeRows();
    $released = [
        $statements(static fn () => $rows->release($notices->type, $two, VariantKey::shared(), LocalizedEntries::shared('2026-03-05', 5))),
        $statements(static fn () => $rows->release($notices->type, $six, VariantKey::shared(), LocalizedEntries::shared('2026-03-05', 5))),
    ];
    $afterRelease = noticeRows();
    $unreleased = [
        $statements(static fn () => $rows->unrelease($notices->type, $two, VariantKey::shared())),
        $statements(static fn () => $rows->unrelease($notices->type, $six, VariantKey::shared())),
    ];

    expect($drafted)->toBe([$drafted[0], $drafted[0]])
        ->and($drafted[0])->toBeGreaterThan(0)
        ->and($released)->toBe([$released[0], $released[0]])
        ->and($unreleased)->toBe([$unreleased[0], $unreleased[0]])
        // The shared draft gives every language a draft row with the new date and priority beside its released row.
        ->and($afterDraft)->toBe(noticeExpected(['draft' => ['2026-03-05', 5], 'released' => ['2026-03-01', 1]]))
        // The shared release moves the new values into every released row, and the equal drafts go.
        ->and($afterRelease)->toBe(noticeExpected(['released' => ['2026-03-05', 5]]))
        // The shared unrelease removes every released row and keeps the values as each language's draft.
        ->and(noticeRows())->toBe(noticeExpected(['draft' => ['2026-03-05', 5]]));
});

it('pages the released rows of one language through the generated query builder, and refuses to page without a locale', function (): void {
    $world = new EntryWorld;
    noticeReleasedIn(1, LOCALIZED_TWO);
    noticeReleasedIn(2, LOCALIZED_SIX);
    LocalizedEntries::notice()->write(LocalizedEntries::entry(3), EntryWorld::home(), LocalizedEntries::shared('2026-03-04', 4), null, ['da' => [LocalizedEntries::translated('Kladde'), null]]);
    $titles = static fn (RecordPage $page): array => array_map(static fn (EntryRecord $record): string => $record->record instanceof AppFixtureNoticeRecord ? $record->record->fixtureTitle.' '.$record->record->fixturePublishedOn->format('Y-m-d') : '', $page->records);

    $da = noticePage($world, static fn (AccessContext $access): RecordPage => app(AppFixtureNoticeQuery::class)->locale(new Locale('da'))->orderBy(AppFixtureNoticeSortField::FixtureTitle)->page($access));
    $fi = noticePage($world, static fn (AccessContext $access): RecordPage => app(AppFixtureNoticeQuery::class)->locale(new Locale('fi'))->page($access));

    expect($titles($da))->toBe(['Title da 2026-03-01', 'Title da 2026-03-01'])
        ->and(array_map(static fn (EntryRecord $record): string => $record->entry->toString(), $da->records))->toBe([LocalizedEntries::entry(1)->toString(), LocalizedEntries::entry(2)->toString()])
        ->and($titles($fi))->toBe(['Title fi 2026-03-01'])
        ->and(static fn (): RecordPage => noticePage($world, static fn (AccessContext $access): RecordPage => app(AppFixtureNoticeQuery::class)->page($access)))
        ->toThrow(InvalidTypeTableQuery::class, 'The type app:fixture_notice has localization variants, so a query of its table reads the rows of one language: give the builder a locale.');
});

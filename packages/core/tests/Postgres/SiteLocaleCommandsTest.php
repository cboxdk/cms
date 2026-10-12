<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteDirectory;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/*
 * site.add_locale and site.remove_locale through the real pipeline on Postgres (PRD 5.9, 5.10,
 * 6.2, 11.14, gap report G30): an add writes the locale's row and the root route in the locale as
 * the app role under the call's actor context, moves the site's version and tells site.locale_added;
 * a removal retires the row, keeps the routes with it, moves the version and tells
 * site.locale_removed, and an add of the retired locale brings the row and its routes back. A
 * removal is site_locale_in_use while a placement locale in the locale is hidden, scheduled or
 * live, with the count; a repeat of a call replays one changeset; and a removal that races a
 * placement.create in the locale on two connections ends in one commit and one version_conflict,
 * whichever commits first.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const SITE_LOCALE_ENTRY = '0192a0c0-0000-7000-8000-0000000006e1';

const SITE_LOCALE_PLACEMENT = '0192a0c0-0000-7000-8000-0000000006c1';

const SITE_LOCALE_OTHER_PLACEMENT = '0192a0c0-0000-7000-8000-0000000006c2';

/**
 * @return list<string>
 */
function siteLocaleCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * Each locale of the site as "<locale> <retired or live>", read as the superuser.
 *
 * @return list<string>
 */
function siteLocaleRows(StructureSite $site): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select locale || ' ' || case when retired_at is null then 'live' else 'retired' end as value from site_locales where site_id = ?::uuid order by locale",
        [$site->id->toString()],
    );
}

/**
 * Each route of the site in the locale as "<route> <node>", read as the superuser.
 *
 * @return list<string>
 */
function siteLocaleRoutes(StructureSite $site, string $locale): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select route || ' ' || node_id::text as value from node_routes where site_id = ?::uuid and locale = ? order by route",
        [$site->id->toString(), $locale],
    );
}

/**
 * The site events as "<type> <site> <version> <locale>", read as the superuser.
 *
 * @return list<string>
 */
function siteLocaleEvents(): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select type || ' ' || aggregate_id::text || ' ' || aggregate_version::text || ' ' || (data->'locale'->>'identifier') as value from events where type like 'site.locale_%' order by event_id",
        [],
    );
}

function siteLocaleDirectory(StructureSite $site): StoredSite
{
    return new PostgresSiteDirectory(app(ConnectionResolverInterface::class))->find($site->id) ?? throw new LogicException('The site is registered.');
}

it('adds a locale with its root route, retires it with its routes kept, and brings it back, telling about each', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $north = $structure->north;

    $added = $world->addLocale($north, 'de', 'add-de');
    $afterAdd = siteLocaleDirectory($north);
    $removed = $world->removeLocale($north, 'de', 'remove-de', version: 2);
    $afterRemove = siteLocaleDirectory($north);
    $again = $world->addLocale($north, 'de', 'add-de-again', version: 3);
    $afterAgain = siteLocaleDirectory($north);
    $audit = StorageTables::texts(StorageTables::superuser(), "select command || ' ' || array_to_string(aggregates, ' ') as value from audit where command in ('site.add_locale', 'site.remove_locale') order by changeset_id", []);

    expect($added->outcome())->toBe(Outcome::Committed)
        ->and($removed->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and(StoredSite::tags($afterAdd->locales))->toBe(['da', 'de', 'en'])
        ->and($afterAdd->version->value)->toBe(2)
        ->and(StoredSite::tags($afterRemove->locales))->toBe(['da', 'en'])
        ->and($afterRemove->version->value)->toBe(3)
        ->and(StoredSite::tags($afterAgain->locales))->toBe(['da', 'de', 'en'])
        ->and($afterAgain->version->value)->toBe(4)
        ->and(siteLocaleRows($north))->toBe(['da live', 'de live', 'en live'])
        ->and(siteLocaleRoutes($north, 'de'))->toBe(['/ '.$north->root->id->toString()])
        ->and(siteLocaleEvents())->toBe([
            'site.locale_added '.$north->id->toString().' 2 de',
            'site.locale_removed '.$north->id->toString().' 3 de',
            'site.locale_added '.$north->id->toString().' 4 de',
        ])
        ->and($audit)->toBe([
            'site.add_locale site:'.$north->id->toString(),
            'site.remove_locale site:'.$north->id->toString(),
            'site.add_locale site:'.$north->id->toString(),
        ]);
});

it('keeps a retired locale\'s row and routes, and the public no longer resolves in it', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $north = $structure->north;

    $removed = $world->removeLocale($north, 'en', 'remove-en');

    expect($removed->outcome())->toBe(Outcome::Committed)
        ->and(siteLocaleRows($north))->toBe(['da live', 'en retired'])
        ->and(siteLocaleRoutes($north, 'en'))->toBe(['/ '.$north->root->id->toString()])
        ->and(StoredSite::tags(siteLocaleDirectory($north)->locales))->toBe(['da']);
});

it('refuses to retire a locale while a placement in it is hidden, scheduled or live, with the count, and retires it once every one is withdrawn', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $north = $structure->north;
    $entry = EntryId::fromString(SITE_LOCALE_ENTRY);
    $world->createEntry($entry, $structure->northSection, 'entry');
    $placed = $world->place(PlacementId::fromString(SITE_LOCALE_PLACEMENT), $entry, $structure->northSection, $north, ['en' => 'harbour'], 'place');

    $hidden = $world->removeLocale($north, 'en', 'remove-hidden');
    $world->setWindow(PlacementId::fromString(SITE_LOCALE_PLACEMENT), 1, null, 'window', 'en');
    $live = $world->removeLocale($north, 'en', 'remove-live');

    expect($placed->outcome())->toBe(Outcome::Committed)
        ->and(siteLocaleCodes($hidden))->toBe(['site_locale_in_use'])
        ->and($hidden->errors[0]->message)->toBe(sprintf('The site %s still has 1 placement in en that is not withdrawn. Withdraw it, or move the content to another locale, and run the command again.', $north->id->toString()))
        ->and(siteLocaleCodes($live))->toBe(['site_locale_in_use'])
        ->and(siteLocaleRows($north))->toBe(['da live', 'en live']);
});

it('refuses the last locale, a locale the site does not publish in, and a locale it publishes in already', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->south->root]);
    $south = $structure->south;

    $last = $world->removeLocale($south, 'da', 'remove-last');
    $unknown = $world->removeLocale($south, 'en', 'remove-unknown');
    $twice = $world->addLocale($south, 'da', 'add-twice');

    expect(siteLocaleCodes($last))->toBe(['validation_failed'])
        ->and($last->errors[0]->message)->toContain('last one')
        ->and(siteLocaleCodes($unknown))->toBe(['validation_failed'])
        ->and(siteLocaleCodes($twice))->toBe(['validation_failed'])
        ->and(siteLocaleRows($south))->toBe(['da live']);
});

it('replays one changeset for a repeat of the same call', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);

    $first = $world->addLocale($structure->north, 'de', 'add-de');
    $again = $world->addLocale($structure->north, 'de', 'add-de');

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($first->receipt->changesetId?->toString())
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'site.add_locale')->count())->toBe(1)
        ->and(siteLocaleRows($structure->north))->toBe(['da live', 'de live', 'en live']);
});

it('ends a removal that races a placement.create in the locale in one commit and one version_conflict, never an orphaned placement', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $remover = new NodeWorld($regions, $first->getName(), seed: 1);
    $placer = new NodeWorld($regions, $second->getName(), seed: 2);
    $entry = EntryId::fromString(SITE_LOCALE_ENTRY);
    $placer->createEntry($entry, $structure->northSection, 'entry');

    $placed = null;
    $remover->meanwhile = static function () use ($placer, $entry, $structure, &$placed): void {
        $placed = $placer->place(PlacementId::fromString(SITE_LOCALE_PLACEMENT), $entry, $structure->northSection, $structure->north, ['en' => 'harbour'], 'place-meanwhile');
    };
    $removed = $remover->removeLocale($structure->north, 'en', 'remove-racing');

    expect($placed?->outcome())->toBe(Outcome::Committed)
        ->and(siteLocaleCodes($removed))->toBe(['version_conflict'])
        ->and($removed->errors[0]->message)->toContain('site_locale_use:'.$structure->north->id->toString().':en')
        ->and(siteLocaleRows($structure->north))->toBe(['da live', 'en live']);
});

it('ends a placement.create that races a removal of its locale in one commit and one version_conflict', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $placer = new NodeWorld($regions, $first->getName(), seed: 1);
    $remover = new NodeWorld($regions, $second->getName(), seed: 2);
    $entry = EntryId::fromString(SITE_LOCALE_ENTRY);
    $placer->createEntry($entry, $structure->northSection, 'entry');

    $removed = null;
    $placer->meanwhile = static function () use ($remover, $structure, &$removed): void {
        $removed = $remover->removeLocale($structure->north, 'en', 'remove-meanwhile');
    };
    $placed = $placer->place(PlacementId::fromString(SITE_LOCALE_OTHER_PLACEMENT), $entry, $structure->northSection, $structure->north, ['en' => 'harbour'], 'place-racing');

    expect($removed?->outcome())->toBe(Outcome::Committed)
        ->and(siteLocaleCodes($placed))->toBe(['version_conflict'])
        ->and(siteLocaleRows($structure->north))->toBe(['da live', 'en retired'])
        ->and(StorageTables::superuser()->table('placement_locales')->where('locale', 'en')->count())->toBe(0);
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use DateTimeImmutable;

/*
 * A writer sets only the fields it may read (PRD 2.31, 12.2; security review S-2 and S-3), through
 * the real command pipeline on Postgres with the workbench's types: fixture_article, history full,
 * whose fixture_sources is internal, and fixture_event, history audit-only, whose
 * fixture_organiser_email is internal and closed to agents. A staff member with public access and
 * an agent are refused a value for a field they may not read, at its path, and nothing is written;
 * when they revise an entry whose closed fields hold values, the new revision, or the head
 * snapshot, and the type table's row keep those values.
 */

const WRITE_ACCESS_EVENT = 'app:fixture_event';

const WRITE_ACCESS_EMAIL = 'organiser@example.org';

const WRITE_ACCESS_SOURCES = [['fixture_source_title' => 'A "quoted" source', 'fixture_source_url' => 'https://example.org/source']];

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * The world's caller: a staff member with public access, or an agent with the access given.
 *
 * @param  string  $caller  'agent', or 'public' for a staff member
 */
function writeAccessAs(EntryWorld $world, string $caller, ClassificationAccess $access): EntryWorld
{
    $world->credential = $caller === 'agent' ? IssuerKind::Agent : IssuerKind::Service;
    $world->classification = $access;

    return $world;
}

/**
 * An event with its required fields, and more when given.
 *
 * @param  array<string, FieldValue>  $more
 */
function writeAccessEvent(string $name, array $more = []): FieldValues
{
    return EntryFields::of([
        ...$more,
        'fixture_name' => new TextValue($name),
        'fixture_starts_at' => new DateTimeValue(new DateTimeImmutable('2026-04-01T19:30:00.000000+00:00')),
        'fixture_kind' => new TextValue('fixture_concert'),
    ]);
}

/**
 * @return list<string> each error as "<code> <path>"
 */
function writeAccessErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
}

/**
 * A JSON value read as the superuser, decoded to arrays.
 */
function writeAccessJson(mixed $value): mixed
{
    return is_string($value) ? json_decode($value, true) : null;
}

/**
 * The row of the entry in a table, read as the superuser, with the columns given.
 *
 * @return array<string, mixed>
 */
function writeAccessRow(string $table, string $entryColumn, string ...$columns): array
{
    $row = StorageTables::superuser()->table($table)->where($entryColumn, EntryWorld::ENTRY)->first($columns);
    $values = [];

    foreach ($row === null ? [] : get_object_vars($row) as $column => $value) {
        $values[(string) $column] = $value;
    }

    return $values;
}

/**
 * The payload of the article's revision with the number, read as the superuser.
 *
 * @return array<mixed>
 */
function writeAccessPayload(int $number): array
{
    $content = StorageTables::superuser()->table('revisions as r')
        ->join('revision_payloads as p', 'p.revision_id', '=', 'r.revision_id')
        ->where('r.entry_id', EntryWorld::ENTRY)
        ->where('r.rev_no', $number)
        ->value('p.content');
    $decoded = writeAccessJson($content);

    return is_array($decoded) ? $decoded : [];
}

it('refuses a value for a field the caller may not read at its path, and writes nothing', function (string $caller, ClassificationAccess $access, string $type, FieldValues $fields, string $path): void {
    $world = writeAccessAs(new EntryWorld, $caller, $access);

    $result = $world->create(EntryWorld::type($type)->id, $fields);

    expect(writeAccessErrors($result))->toBe(['unauthorized -', 'unauthorized '.$path])
        ->and(EntryWorld::rows())->toBe(array_fill_keys(EntryWorld::TABLES, 0));
})->with([
    'an internal field from a public-ceiling actor' => ['public', ClassificationAccess::Public, EntryWorld::ARTICLE, EntryFields::article(), 'fields.fixture_sources'],
    'an internal field from an agent with public access' => ['agent', ClassificationAccess::Public, EntryWorld::ARTICLE, EntryFields::article(), 'fields.fixture_sources'],
    'an internal field closed to agents from a public-ceiling actor' => ['public', ClassificationAccess::Public, WRITE_ACCESS_EVENT, writeAccessEvent('Harbour songs', ['fixture_organiser_email' => new TextValue(WRITE_ACCESS_EMAIL)]), 'fields.fixture_organiser_email'],
    'a field closed to agents from an agent with internal access' => ['agent', ClassificationAccess::Internal, WRITE_ACCESS_EVENT, writeAccessEvent('Harbour songs', ['fixture_organiser_email' => new TextValue(WRITE_ACCESS_EMAIL)]), 'fields.fixture_organiser_email'],
]);

it('keeps the internal sources of an article with full history in the new revision and the draft row when a caller who may not read them revises it', function (string $caller, ClassificationAccess $access): void {
    $world = new EntryWorld;
    $world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article());
    $revised = writeAccessAs($world, $caller, $access)->revise(1, EntryFields::of(['fixture_title' => new TextValue('A revised title'), 'fixture_featured' => new BooleanValue(false)]));

    $row = writeAccessRow('app__fixture_article', 'cms_entry_id', 'cms_stage', 'fixture_title', 'fixture_sources', 'fixture_reading_minutes');

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and(writeAccessPayload(2))->toEqual(['fixture_featured' => false, 'fixture_sources' => WRITE_ACCESS_SOURCES, 'fixture_title' => 'A revised title'])
        ->and([$row['cms_stage'], $row['fixture_title'], $row['fixture_reading_minutes']])->toBe(['draft', 'A revised title', null])
        ->and(writeAccessJson($row['fixture_sources']))->toEqual(WRITE_ACCESS_SOURCES)
        ->and(StorageTables::superuser()->table('app__fixture_article')->count())->toBe(1);
})->with([
    'a public-ceiling actor' => ['public', ClassificationAccess::Public],
    'an agent with public access' => ['agent', ClassificationAccess::Public],
]);

it('keeps the organiser\'s e-mail of an event with audit-only history in the head snapshot and the released row when a caller who may not read it revises it', function (string $caller, ClassificationAccess $access): void {
    $world = new EntryWorld;
    $world->create(EntryWorld::type(WRITE_ACCESS_EVENT)->id, writeAccessEvent('Harbour songs', ['fixture_organiser_email' => new TextValue(WRITE_ACCESS_EMAIL)]));
    $revised = writeAccessAs($world, $caller, $access)->revise(1, writeAccessEvent('Harbour songs, second night'));

    $snapshot = writeAccessRow('head_snapshots', 'entry_id', 'rev_no', 'content');
    $row = writeAccessRow('app__fixture_event', 'cms_entry_id', 'cms_stage', 'fixture_name', 'fixture_organiser_email');
    $content = writeAccessJson($snapshot['content'] ?? null);

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and($snapshot['rev_no'] ?? null)->toBe(2)
        ->and(is_array($content) ? [$content['fixture_name'] ?? null, $content['fixture_organiser_email'] ?? null] : [])->toBe(['Harbour songs, second night', WRITE_ACCESS_EMAIL])
        ->and([$row['cms_stage'], $row['fixture_name'], $row['fixture_organiser_email']])->toBe(['released', 'Harbour songs, second night', WRITE_ACCESS_EMAIL])
        ->and(StorageTables::superuser()->table('app__fixture_event')->count())->toBe(1)
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(0);
})->with([
    'a public-ceiling actor' => ['public', ClassificationAccess::Public],
    'an agent with internal access' => ['agent', ClassificationAccess::Internal],
]);

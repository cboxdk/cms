<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Events\Boundary\EventDataJson;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Illuminate\Support\Facades\DB;

/*
 * variant.release through the real command pipeline on Postgres (PRD 4.1, 5.6, 6.4): a release of
 * a draft revision writes a published revision after the variant's highest number with a copy of
 * the draft's payload, points the head at it with the release state released, logs it in
 * release_log, writes the type table's released row from the revision and keeps the draft row only
 * where the pending draft differs, and emits variant.released, all in one changeset. The workbench's
 * fixture_article has full history and stages draft-release; fixture_measurement has neither, so
 * it is type_not_releasable. A stale version is version_conflict, a revision the variant does not
 * have or that breaks the type's rules at the release stage is rejected, and nothing of a rejected
 * call is kept. A release costs the same queries however many revisions the variant has.
 */

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * @return list<string>
 */
function releaseCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The rows of a table for the entry, as the superuser, ordered by the column given, each as an
 * array of the columns asked for.
 *
 * @return list<array<string, mixed>>
 */
function releaseRows(string $table, string $entryColumn, string $orderBy, string ...$columns): array
{
    $rows = [];

    foreach (StorageTables::superuser()->table($table)->where($entryColumn, EntryWorld::ENTRY)->orderBy($orderBy)->get($columns) as $row) {
        $values = [];

        foreach (get_object_vars($row) as $column => $value) {
            $values[(string) $column] = $value;
        }

        $rows[] = $values;
    }

    return $rows;
}

/**
 * The stage and title of each row of the entry in the article's type table.
 *
 * @return list<array<string, mixed>>
 */
function releaseArticleRows(): array
{
    return releaseRows('app__fixture_article', 'cms_entry_id', 'cms_stage', 'cms_stage', 'fixture_title');
}

/**
 * The number of rows each table the release writes holds.
 *
 * @return array<string, int>
 */
function releaseCounts(): array
{
    $superuser = StorageTables::superuser();

    return [
        ...EntryWorld::rows(),
        'release_log' => $superuser->table('release_log')->count(),
        'app__fixture_article' => $superuser->table('app__fixture_article')->count(),
        'app__fixture_measurement' => $superuser->table('app__fixture_measurement')->count(),
    ];
}

it('releases a draft as a published revision: the head, the release log, the released row and variant.released', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article('The harbour opens'));

    $result = $world->release(1, 1);

    $revisions = releaseRows('revisions', 'entry_id', 'rev_no', 'revision_id', 'rev_no', 'kind', 'schema_version', 'changeset_id');
    $published = $revisions[1]['revision_id'] ?? null;
    $payloads = StorageTables::superuser()->table('revision_payloads')->orderBy('kind')->get(['revision_id', 'kind', 'content'])->all();
    $head = releaseRows('variant_heads', 'entry_id', 'variant', 'draft_revision_id', 'published_revision_id', 'release_state', 'version');
    $event = StorageTables::superuser()->table('events')->where('type', 'variant.released')->first(['aggregate_version', 'data']);
    $data = EventDataJson::decode(is_object($event) && property_exists($event, 'data') && is_string($event->data) ? $event->data : '{}');
    $changeset = $result->receipt->changesetId instanceof ChangesetId ? $result->receipt->changesetId->toString() : null;

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(array_map(static fn (array $row): array => [$row['rev_no'], $row['kind'], $row['schema_version']], $revisions))->toBe([[1, 'draft', $type->version], [2, 'published', $type->version]])
        ->and($revisions[1]['changeset_id'])->toBe($changeset)
        ->and(array_map(static fn (object $row): array => [$row->kind ?? null, $row->revision_id ?? null], $payloads))->toBe([['draft', $revisions[0]['revision_id']], ['published', $published]])
        ->and(json_decode(is_string($payloads[1]->content ?? null) ? $payloads[1]->content : '', true))->toEqual(json_decode(is_string($payloads[0]->content ?? null) ? $payloads[0]->content : '', true))
        ->and($head)->toBe([['draft_revision_id' => $revisions[0]['revision_id'], 'published_revision_id' => $published, 'release_state' => 'released', 'version' => 2]])
        ->and(releaseRows('release_log', 'entry_id', 'release_id', 'variant', 'action', 'revision_id', 'effective_at', 'changeset_id'))->toBe([[
            'variant' => 'shared', 'action' => 'released', 'revision_id' => $published, 'effective_at' => '2026-03-10 12:00:00+00', 'changeset_id' => $changeset,
        ]])
        ->and(releaseArticleRows())->toBe([['cms_stage' => 'released', 'fixture_title' => 'The harbour opens']])
        ->and(releaseRows('app__fixture_article', 'cms_entry_id', 'cms_stage', 'fixture_featured', 'fixture_reading_minutes', 'fixture_topics'))
        ->toBe([['fixture_featured' => true, 'fixture_reading_minutes' => 4, 'fixture_topics' => '{fixture_science,fixture_culture}']])
        ->and(is_object($event) && property_exists($event, 'aggregate_version') ? $event->aggregate_version : null)->toBe(2)
        ->and([$data->get('entry')->asIdentifier()->value, $data->get('variant')->asIdentifier()->value, $data->get('revision')->asInteger(), $data->get('published')->asInteger(), $data->get('previous')->isNull()])
        ->toBe([EntryWorld::ENTRY, EntryWorld::ENTRY.':shared', 1, 2, true]);
});

it('keeps a draft that differs from the released row, numbers the next revision after the published one, and releases it', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article('The harbour opens'));
    $world->release(1, 1);

    $revised = $world->revise(2, EntryFields::article('The harbour opens on Friday'));
    $whileDiffering = releaseArticleRows();
    $released = $world->release(3, 3, 'release-2');
    $event = StorageTables::superuser()->table('events')->where('type', 'variant.released')->where('aggregate_version', 4)->value('data');
    $data = EventDataJson::decode(is_string($event) ? $event : '{}');

    expect([$revised->outcome(), $released->outcome()])->toBe([Outcome::Committed, Outcome::Committed])
        ->and(array_map(static fn (array $row): array => [$row['rev_no'], $row['kind']], releaseRows('revisions', 'entry_id', 'rev_no', 'rev_no', 'kind')))
        ->toBe([[1, 'draft'], [2, 'published'], [3, 'draft'], [4, 'published']])
        ->and($whileDiffering)->toBe([
            ['cms_stage' => 'draft', 'fixture_title' => 'The harbour opens on Friday'],
            ['cms_stage' => 'released', 'fixture_title' => 'The harbour opens'],
        ])
        ->and(releaseArticleRows())->toBe([['cms_stage' => 'released', 'fixture_title' => 'The harbour opens on Friday']])
        ->and(StorageTables::superuser()->table('release_log')->count())->toBe(2)
        ->and([$data->get('revision')->asInteger(), $data->get('published')->asInteger(), $data->get('previous')->asInteger()])->toBe([3, 4, 2]);
});

it('releases an older revision again and keeps the pending draft, which now differs, as the draft row', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article('First words'));
    $world->release(1, 1);
    $world->revise(2, EntryFields::article('Second words'));
    $world->release(3, 3, 'release-2');
    $afterSecond = releaseArticleRows();

    $back = $world->release(4, 2, 'release-back');

    expect($back->outcome())->toBe(Outcome::Committed)
        ->and($afterSecond)->toBe([['cms_stage' => 'released', 'fixture_title' => 'Second words']])
        ->and(releaseArticleRows())->toBe([
            ['cms_stage' => 'draft', 'fixture_title' => 'Second words'],
            ['cms_stage' => 'released', 'fixture_title' => 'First words'],
        ])
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(4)
        ->and(StorageTables::superuser()->table('variant_heads')->value('published_revision_id'))
        ->toBe(StorageTables::superuser()->table('revisions')->where('rev_no', 2)->value('revision_id'));
});

it('rejects a release of a type with stages none, a stale version, a revision the variant does not have and one that breaks the type\'s rules, and keeps nothing', function (): void {
    $world = new EntryWorld;
    $article = EntryWorld::type(EntryWorld::ARTICLE);
    $created = $world->create($article->id, EntryFields::article());
    $measurement = EntryId::fromString('0192a0c0-0000-7000-8000-0000000001e2');
    $world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, EntryFields::measurement(), 'create-measurement', $measurement);
    $superuser = StorageTables::superuser();
    $superuser->table('revisions')->insert([
        'entry_id' => EntryWorld::ENTRY, 'variant' => 'shared', 'rev_no' => 2, 'kind' => 'draft', 'schema_version' => $article->version,
        'changeset_id' => $created->receipt->changesetId?->toString(), 'created_at' => StorageTables::CREATED_AT,
    ]);
    $superuser->table('revision_payloads')->insert([
        'revision_id' => $superuser->table('revisions')->where('rev_no', 2)->value('revision_id'), 'kind' => 'draft', 'format_version' => 1,
        'content' => '{"fixture_title":"No flag"}',
    ]);
    $before = releaseCounts();

    $unstaged = $world->release(1, 1, 'release-measurement', $measurement);
    $stale = $world->release(2, 1, 'release-stale');
    $unknown = $world->release(1, 9, 'release-unknown');
    $invalid = $world->release(1, 2, 'release-invalid');

    expect(releaseCodes($unstaged))->toBe(['type_not_releasable'])
        ->and($unstaged->errors[0]->message)->toContain('app:fixture_measurement has stages none')
        ->and(releaseCodes($stale))->toBe(['version_conflict'])
        ->and(releaseCodes($unknown))->toBe(['validation_failed', 'validation_failed'])
        ->and($unknown->errors[1]->message)->toContain('has no revision 9')
        ->and(releaseCodes($invalid))->toBe(['validation_failed', 'validation_required'])
        ->and($invalid->errors[1]->path?->toString())->toBe('revision.fixture_featured')
        ->and(releaseCounts())->toBe($before)
        ->and(StorageTables::superuser()->table('variant_heads')->where('entry_id', EntryWorld::ENTRY)->value('release_state'))->toBe('unreleased');
});

it('releases a revision with the same queries whether the variant has 20 or 200 revisions', function (): void {
    $counts = [];

    foreach ([20, 200] as $revisions) {
        $world = new EntryWorld(seed: $revisions);
        $type = EntryWorld::type(EntryWorld::ARTICLE);
        $entry = EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 6000 + $revisions));
        $created = $world->create($type->id, EntryFields::article(), 'create-'.$revisions, $entry);
        releaseEarlierRevisions($entry, $created, $revisions);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $result = $world->release(1, $revisions, 'release-'.$revisions, $entry);
        $connection->disableQueryLog();
        $counts[$revisions] = [$result->outcome(), count($connection->getQueryLog())];
        $connection->flushQueryLog();
    }

    expect($counts[20][0])->toBe(Outcome::Committed)
        ->and($counts[200][0])->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(20 + 1 + 200 + 1)
        ->and($counts[200][1])->toBe($counts[20][1]);
});

/**
 * Revisions 2 to $count of the entry's shared variant, drafts with the payload of revision 1 and
 * the changeset of its create, written as the superuser, with the head's draft on the last, so the
 * variant has $count revisions.
 */
function releaseEarlierRevisions(EntryId $entry, WriteResult $created, int $count): void
{
    $superuser = StorageTables::superuser();
    $first = $superuser->table('revisions')->where('entry_id', $entry->toString())->where('rev_no', 1)->first(['revision_id', 'schema_version']);
    $firstId = is_object($first) && property_exists($first, 'revision_id') ? $first->revision_id : null;
    $content = $superuser->table('revision_payloads')->where('revision_id', $firstId)->value('content');
    $revisions = [];

    for ($number = 2; $number <= $count; $number++) {
        $revisions[] = [
            'entry_id' => $entry->toString(), 'variant' => 'shared', 'rev_no' => $number, 'kind' => 'draft',
            'schema_version' => is_object($first) && property_exists($first, 'schema_version') ? $first->schema_version : 1,
            'changeset_id' => $created->receipt->changesetId?->toString(), 'created_at' => StorageTables::CREATED_AT,
        ];
    }

    $superuser->table('revisions')->insert($revisions);
    $payloads = [];

    foreach ($superuser->table('revisions')->where('entry_id', $entry->toString())->where('rev_no', '>', 1)->pluck('revision_id') as $id) {
        $payloads[] = ['revision_id' => $id, 'kind' => 'draft', 'format_version' => 1, 'content' => $content];
    }

    $superuser->table('revision_payloads')->insert($payloads);
    $superuser->table('variant_heads')->where('entry_id', $entry->toString())
        ->update(['draft_revision_id' => $superuser->table('revisions')->where('entry_id', $entry->toString())->where('rev_no', $count)->value('revision_id')]);
}

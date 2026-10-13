<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\LocalizedEntries;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The relation edges (PRD 5.11, B2-T112) through the real command pipeline on Postgres: every
 * relation, to-one and to-many, is a row of `relations` per source entry, variant, stage, field and
 * ordinal, with the target a foreign key to entries ON DELETE RESTRICT, written in the transaction
 * that writes the variant's type row. The workbench's fixture_measurement, whose stages are none,
 * has a to-one relation, a to-many one and a to-one inside a group, written in the released stage;
 * fixture_notice, with stages draft-release and localization variants, has a shared to-many
 * relation, fixture_related, and a translatable one, fixture_sources, so each variant has edges of
 * its own in the draft stage, and in the released stage once released, as fixture_article's
 * fixture_related has. entry.unpublish unreleases the article and removes its released edges,
 * keeping the draft's, variant.delete removes a language's edges in both stages, and
 * cms:types:rebuild writes the same edges again. The edges of a run are one statement, so a command
 * with 20 targets runs as many statements as one with 200.
 */

const EDGE_PLACEMENT = '0192a0c0-0000-7000-8000-0000000007e1';

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

function edgeEntry(int $number): EntryId
{
    return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 400_000 + $number));
}

/**
 * @param  list<EntryId>  $entries
 */
function edgeList(array $entries): ListValue
{
    return new ListValue(...array_map(static fn (EntryId $entry): TextValue => new TextValue($entry->toString()), $entries));
}

/**
 * The edges of the entry as "<variant> <stage> <field> <ordinal> <target's number>", sorted, read
 * as the superuser past row level security. A target is named by its number of edgeEntry().
 *
 * @return list<string>
 */
function edgeRows(EntryId $entry): array
{
    $edges = [];

    foreach (StorageTables::superuser()->table('relations')->where('source_entry_id', $entry->toString())
        ->orderBy('variant')->orderBy('stage')->orderBy('field')->orderBy('ordinal')->get() as $edge) {
        $target = is_string($edge->target_entry_id) ? $edge->target_entry_id : '';
        $edges[] = implode(' ', [
            is_string($edge->variant) ? $edge->variant : '',
            is_string($edge->stage) ? $edge->stage : '',
            is_string($edge->field) ? $edge->field : '',
            is_int($edge->ordinal) ? (string) $edge->ordinal : '',
            (string) ((int) substr($target, -12) - 400_000),
        ]);
    }

    return $edges;
}

/**
 * Every row of `relations`, in key order, as the superuser reads it.
 *
 * @return list<string>
 */
function edgeTable(): array
{
    $rows = [];

    foreach (StorageTables::superuser()->table('relations')->orderBy('source_entry_id')->orderBy('variant')->orderBy('stage')->orderBy('field')->orderBy('ordinal')->get() as $row) {
        $rows[] = implode(' ', array_map(static fn (mixed $value): string => is_string($value) || is_int($value) ? (string) $value : '', get_object_vars($row)));
    }

    return $rows;
}

/**
 * The placement structure and a publishing world whose actor reaches north's section, with the
 * articles 1 to $count created there as the targets the tests name.
 *
 * @return array{PublishingWorld, PlacementStructure, list<EntryId>}
 */
function edgeWorld(int $count = 6): array
{
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->northSection], seed: 11);
    $targets = [];

    foreach (range(1, $count) as $number) {
        $target = edgeEntry($number);
        expect($world->createEntry($target, EntryWorld::ARTICLE, EntryFields::article('Article '.$number), $structure->northSection, 'target-'.$number)->outcome())->toBe(Outcome::Committed);
        $targets[] = $target;
    }

    return [$world, $structure, $targets];
}

/**
 * A measurement's fields with the relation values given, by handle.
 *
 * @param  array<string, FieldValue>  $relations
 */
function edgeMeasurement(array $relations): FieldValues
{
    $fields = [];

    foreach (EntryFields::measurement()->own->fields as $named) {
        $fields[$named->handle->value] = $named->value;
    }

    return EntryFields::of([...$fields, ...$relations]);
}

/**
 * The notice's shared fields with its related entries.
 *
 * @param  list<EntryId>  $related
 */
function edgeShared(array $related): FieldValues
{
    $fields = [];

    foreach (LocalizedEntries::shared('2026-03-09', 3)->own->fields as $named) {
        $fields[$named->handle->value] = $named->value;
    }

    return EntryFields::of([...$fields, 'fixture_related' => edgeList($related)]);
}

/**
 * A language's fields of the notice with its sources.
 *
 * @param  list<EntryId>  $sources
 */
function edgeTranslated(string $title, array $sources): FieldValues
{
    return EntryFields::of(['fixture_title' => new TextValue($title), 'fixture_sources' => edgeList($sources)]);
}

/**
 * The statements the call runs on the default connection.
 *
 * @param  callable(): WriteResult  $call
 * @return array{int, WriteResult}
 */
function edgeStatements(callable $call): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $result = $call();

        return [count($connection->getQueryLog()), $result];
    } finally {
        $connection->disableQueryLog();
    }
}

/**
 * The notice created in da and en with edges in every variant, placed in da below north's section.
 *
 * @param  list<EntryId>  $targets
 */
function edgeNotice(PublishingWorld $world, PlacementStructure $structure, array $targets): EntryId
{
    $notice = EntryWorld::entry();
    $created = $world->createLocalized($notice, LocalizedEntries::NOTICE, edgeShared([$targets[0], $targets[1]]), [
        'da' => edgeTranslated('Havnen åbner', [$targets[2]]),
        'en' => edgeTranslated('The harbour opens', [$targets[3], $targets[4]]),
    ], $structure->northSection, 'create-notice');
    $placed = $world->place(PlacementId::fromString(EDGE_PLACEMENT), $notice, $structure->northSection, $structure->north, 'havnen', 'place-notice');

    expect([$created->outcome(), $placed->outcome()])->toBe([Outcome::Committed, Outcome::Committed]);

    return $notice;
}

it('writes the edges of a type with stages none in the released stage: a to-one, a to-many in order and a to-one inside a group', function (): void {
    [$world, $structure, $targets] = edgeWorld(3);
    $measurement = edgeEntry(100);
    $corrected = edgeEntry(101);
    $type = EntryWorld::MEASUREMENT;

    expect($world->createEntry($corrected, $type, EntryFields::measurement(), $structure->northSection, 'corrected')->outcome())->toBe(Outcome::Committed);

    $created = $world->createEntry($measurement, $type, edgeMeasurement([
        'fixture_corrects' => new TextValue($corrected->toString()),
        'fixture_cited_in' => edgeList([$targets[2], $targets[0], $corrected]),
        'fixture_sensor' => new GroupValue(EntryFields::map([
            'fixture_sensor_code' => new TextValue('S-7'),
            'fixture_sensor_calibration' => new TextValue($corrected->toString()),
        ])),
    ]), $structure->northSection, 'create-measurement');

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($measurement))->toBe([
            'shared released fixture_cited_in 0 3',
            'shared released fixture_cited_in 1 1',
            'shared released fixture_cited_in 2 101',
            'shared released fixture_corrects 0 101',
            'shared released fixture_sensor.fixture_sensor_calibration 0 101',
        ])
        ->and(edgeRows($corrected))->toBe([]);

    // A revise replaces the edges: one target less, another order, and no correction any more.
    $revised = $world->revise($measurement, 1, edgeMeasurement(['fixture_cited_in' => edgeList([$targets[0], $targets[1]])]), 'revise-measurement');

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($measurement))->toBe([
            'shared released fixture_cited_in 0 1',
            'shared released fixture_cited_in 1 2',
        ]);
});

it('writes each variant\'s edges in the draft stage, its released edges on a release, and keeps the released ones while a draft changes', function (): void {
    [$world, $structure, $targets] = edgeWorld();
    $notice = edgeNotice($world, $structure, $targets);

    // Each variant has the edges of its own fields: the shared relation in the shared variant only, each language's sources in the language.
    expect(edgeRows($notice))->toBe([
        'da draft fixture_sources 0 3',
        'en draft fixture_sources 0 4',
        'en draft fixture_sources 1 5',
        'shared draft fixture_related 0 1',
        'shared draft fixture_related 1 2',
    ]);

    $published = $world->publishVariants($notice, ['shared' => [1, 1], 'da' => [1, 1]], PlacementId::fromString(EDGE_PLACEMENT), 1, 'publish');

    expect($published->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($notice))->toBe([
            'da draft fixture_sources 0 3',
            'da released fixture_sources 0 3',
            'en draft fixture_sources 0 4',
            'en draft fixture_sources 1 5',
            'shared draft fixture_related 0 1',
            'shared draft fixture_related 1 2',
            'shared released fixture_related 0 1',
            'shared released fixture_related 1 2',
        ]);

    // A revise of da and the shared variant changes their draft edges; what is released keeps its edges.
    $revised = $world->reviseVariants($notice, [
        'shared' => [2, edgeShared([$targets[5]])],
        'da' => [2, edgeTranslated('Havnen åbner i dag', [$targets[5], $targets[2], $targets[0]])],
    ], 'revise-notice');

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($notice))->toBe([
            'da draft fixture_sources 0 6',
            'da draft fixture_sources 1 3',
            'da draft fixture_sources 2 1',
            'da released fixture_sources 0 3',
            'en draft fixture_sources 0 4',
            'en draft fixture_sources 1 5',
            'shared draft fixture_related 0 6',
            'shared released fixture_related 0 1',
            'shared released fixture_related 1 2',
        ]);

    // Releasing the revised da gives its released stage the draft's edges.
    $released = $world->releaseVariant($notice, 'da', 3, 3, 'release-da');

    expect($released->outcome())->toBe(Outcome::Committed)
        ->and(array_values(array_filter(edgeRows($notice), static fn (string $edge): bool => str_starts_with($edge, 'da released'))))->toBe([
            'da released fixture_sources 0 6',
            'da released fixture_sources 1 3',
            'da released fixture_sources 2 1',
        ]);
});

it('removes the released edges an unpublish unreleases, keeping the draft\'s, and every edge of a deleted language', function (): void {
    [$world, $structure, $targets] = edgeWorld();
    $article = edgeEntry(200);
    $placement = PlacementId::fromString('0192a0c0-0000-7000-8000-0000000007e2');

    expect($world->createEntry($article, EntryWorld::ARTICLE, EntryFields::article(more: ['fixture_related' => edgeList([$targets[0], $targets[1]])]), $structure->northSection, 'create-article')->outcome())->toBe(Outcome::Committed)
        ->and($world->place($placement, $article, $structure->northSection, $structure->north, 'artiklen', 'place-article')->outcome())->toBe(Outcome::Committed)
        ->and($world->publish($article, 1, 1, $placement, 1, 'publish-article')->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($article))->toBe([
            'shared draft fixture_related 0 1',
            'shared draft fixture_related 1 2',
            'shared released fixture_related 0 1',
            'shared released fixture_related 1 2',
        ]);

    // entry.unpublish unreleases the variant: its released edges go, the draft's stay.
    $unpublished = $world->unpublish($article, 2, 'unpublish-article');

    expect($unpublished->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($article))->toBe([
            'shared draft fixture_related 0 1',
            'shared draft fixture_related 1 2',
        ]);

    // variant.delete of a language removes its edges in both stages; the other variants keep theirs.
    $notice = edgeNotice($world, $structure, $targets);
    expect($world->publishVariants($notice, ['shared' => [1, 1], 'da' => [1, 1]], PlacementId::fromString(EDGE_PLACEMENT), 1, 'publish-da')->outcome())->toBe(Outcome::Committed)
        ->and($world->releaseVariant($notice, 'en', 1, 1, 'release-en')->outcome())->toBe(Outcome::Committed)
        ->and(array_values(array_filter(edgeRows($notice), static fn (string $edge): bool => str_starts_with($edge, 'en '))))->toBe([
            'en draft fixture_sources 0 4',
            'en draft fixture_sources 1 5',
            'en released fixture_sources 0 4',
            'en released fixture_sources 1 5',
        ]);

    $deleted = $world->deleteVariant($notice, 'en', 2, 'delete-en');

    expect($deleted->outcome())->toBe(Outcome::Committed)
        ->and(edgeRows($notice))->toBe([
            'da draft fixture_sources 0 3',
            'da released fixture_sources 0 3',
            'shared draft fixture_related 0 1',
            'shared draft fixture_related 1 2',
            'shared released fixture_related 0 1',
            'shared released fixture_related 1 2',
        ]);
});

it('rebuilds the same edges from the heads with cms:types:rebuild', function (): void {
    [$world, $structure, $targets] = edgeWorld();
    $notice = edgeNotice($world, $structure, $targets);
    $measurement = edgeEntry(100);

    expect($world->publishVariants($notice, ['shared' => [1, 1], 'da' => [1, 1]], PlacementId::fromString(EDGE_PLACEMENT), 1, 'publish')->outcome())->toBe(Outcome::Committed)
        ->and($world->reviseVariants($notice, ['da' => [2, edgeTranslated('Havnen åbner i dag', [$targets[5]])]], 'revise-da')->outcome())->toBe(Outcome::Committed)
        ->and($world->deleteVariant($notice, 'en', 1, 'delete-en')->outcome())->toBe(Outcome::Committed)
        ->and($world->createEntry($measurement, EntryWorld::MEASUREMENT, edgeMeasurement(['fixture_cited_in' => edgeList([$targets[1], $targets[2]])]), $structure->northSection, 'create-measurement')->outcome())->toBe(Outcome::Committed);

    $before = edgeTable();
    $rebuild = new RebuildWorld(new EntryWorld, chunkSize: 2, granted: $structure->northSection->id);

    // The rows a rebuild would not write are planted: an edge of the deleted language and a stale released edge.
    $superuser = StorageTables::superuser();
    $superuser->table('relations')->where('source_entry_id', $notice->toString())->where('variant', 'da')->where('stage', 'released')->update(['target_entry_id' => $targets[4]->toString()]);
    $superuser->table('relations')->insert(['source_entry_id' => $notice->toString(), 'variant' => 'en', 'stage' => 'draft', 'field' => 'fixture_sources', 'ordinal' => 0, 'target_entry_id' => $targets[3]->toString()]);
    $superuser->table('relations')->where('source_entry_id', $measurement->toString())->delete();

    expect(edgeTable())->not->toBe($before);

    $rebuild->rebuild(LocalizedEntries::NOTICE);
    $rebuild->rebuild(EntryWorld::MEASUREMENT);

    expect(edgeTable())->toBe($before)
        ->and(edgeRows($notice))->toBe([
            'da draft fixture_sources 0 6',
            'da released fixture_sources 0 3',
            'shared draft fixture_related 0 1',
            'shared draft fixture_related 1 2',
            'shared released fixture_related 0 1',
            'shared released fixture_related 1 2',
        ]);
});

it('runs as many statements for a save and a release with 200 targets as with 20', function (): void {
    [$world, $structure, $targets] = edgeWorld(200);
    $counts = [];

    foreach ([20, 200] as $index => $size) {
        $notice = edgeEntry(500 + $index);
        $placement = PlacementId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 700 + $index));
        $chosen = array_slice($targets, 0, $size);

        [$create, $created] = edgeStatements(static fn (): WriteResult => $world->createLocalized($notice, LocalizedEntries::NOTICE, edgeShared($chosen), [
            'da' => edgeTranslated('Havnen åbner', $chosen),
        ], $structure->northSection, 'create-'.$size));
        expect($world->place($placement, $notice, $structure->northSection, $structure->north, 'havnen-'.$size, 'place-'.$size)->outcome())->toBe(Outcome::Committed);
        [$publish, $published] = edgeStatements(static fn (): WriteResult => $world->publishVariants($notice, ['shared' => [1, 1], 'da' => [1, 1]], $placement, 1, 'publish-'.$size));
        [$revise, $revised] = edgeStatements(static fn (): WriteResult => $world->reviseVariants($notice, ['da' => [2, edgeTranslated('Havnen åbner', array_reverse($chosen))]], 'revise-'.$size));

        expect([$created->outcome(), $published->outcome(), $revised->outcome()])->toBe([Outcome::Committed, Outcome::Committed, Outcome::Committed])
            ->and(StorageTables::superuser()->table('relations')->where('source_entry_id', $notice->toString())->count())->toBe(4 * $size);
        $counts[] = [$create, $publish, $revise];
    }

    expect($counts[1])->toBe($counts[0]);
});

it('keeps an entry with inbound edges from being deleted, and holds relations to forced row level security and indexed foreign keys', function (): void {
    [$world, $structure, $targets] = edgeWorld(1);
    $measurement = edgeEntry(100);

    expect($world->createEntry($measurement, EntryWorld::MEASUREMENT, edgeMeasurement(['fixture_cited_in' => edgeList($targets)]), $structure->northSection, 'create-measurement')->outcome())->toBe(Outcome::Committed);

    $superuser = StorageTables::superuser();
    $table = StorageTables::texts($superuser, "select relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = 'relations'::regclass");
    $keys = StorageTables::texts($superuser, <<<'SQL'
        select conname::text || ' ' || confrelid::regclass::text || ' ' || confdeltype::text as value
        from pg_constraint where conrelid = 'relations'::regclass and contype = 'f' order by 1
        SQL);

    expect($table)->toBe(['true true'])
        ->and($keys)->toBe(['relations_head_fkey variant_heads a', 'relations_target_fkey entries r']);

    // An entry no other row names but an edge is kept from being deleted by the edge's foreign key.
    $bare = edgeEntry(900);
    $superuser->table('entries')->insert([
        'id' => $bare->toString(),
        'type_id' => EntryWorld::type(EntryWorld::ARTICLE)->id->toString(),
        'home_node_id' => $structure->northSection->id->toString(),
        'owner_actor_id' => null,
        'lifecycle' => 'active',
        'version' => 1,
        'created_at' => LocalizedEntries::CREATED_AT,
    ]);
    $superuser->table('relations')->where('source_entry_id', $measurement->toString())->update(['target_entry_id' => $bare->toString()]);

    expect(fn () => $superuser->table('entries')->where('id', $bare->toString())->delete())->toThrow(QueryException::class, 'relations_target_fkey');
});

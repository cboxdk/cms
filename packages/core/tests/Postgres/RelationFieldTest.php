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
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Illuminate\Support\Facades\DB;

/*
 * The relation field type through the real command pipeline on Postgres (PRD 5.11, 11.6, B2-T74).
 * The workbench's fixture_measurement has a to-one relation, fixture_corrects, a uuid column with a
 * foreign key to entries ON DELETE RESTRICT, so a row names no entry that does not exist and no
 * entry it names is deleted; a multiple one, fixture_cited_in, a jsonb list of up to
 * 200 articles or measurements; and a to-one relation inside its group fixture_sensor. A target that
 * does not exist is validation_relation_target_missing and one of another type
 * validation_relation_target_type, at the field's path; the targets of a command are read in one
 * statement, so a command with 2 targets runs as many statements as one with 200.
 */

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/** An entry no command created. */
const RELATION_NOWHERE = '0192a0c0-0000-7000-8000-0000000003f9';

function relationEntry(int $number): EntryId
{
    return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 300_000 + $number));
}

/**
 * A measurement's fields with the relation values given, by handle.
 *
 * @param  array<string, FieldValue>  $relations
 */
function relationMeasurement(array $relations): FieldValues
{
    $fields = [];

    foreach (EntryFields::measurement()->own->fields as $named) {
        $fields[$named->handle->value] = $named->value;
    }

    return EntryFields::of([...$fields, ...$relations]);
}

/**
 * @param  list<EntryId>  $entries
 */
function relationList(array $entries): ListValue
{
    return new ListValue(...array_map(static fn (EntryId $entry): TextValue => new TextValue($entry->toString()), $entries));
}

/**
 * Creates the measurements 1 to $count, the targets the tests name.
 *
 * @return list<EntryId>
 */
function relationTargets(EntryWorld $world, int $count): array
{
    $type = EntryWorld::type(EntryWorld::MEASUREMENT)->id;
    $targets = [];

    foreach (range(1, $count) as $number) {
        $entry = relationEntry($number);
        expect($world->create($type, EntryFields::measurement(), 'target-'.$number, $entry)->outcome())->toBe(Outcome::Committed);
        $targets[] = $entry;
    }

    return $targets;
}

/**
 * The code and the path of each error after the call's first, validation_failed.
 *
 * @return list<array{string, ?string}>
 */
function relationErrors(WriteResult $result): array
{
    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->errors[0]->code->value)->toBe('validation_failed');

    return array_map(static fn (CatalogError $error): array => [$error->code->value, $error->path?->toString()], array_slice($result->errors, 1));
}

/**
 * Creates measurement $number that cites the entries.
 *
 * @param  list<EntryId>  $cited
 */
function relationCiting(EntryWorld $world, TypeId $type, array $cited, string $key, int $number): WriteResult
{
    return $world->create($type, relationMeasurement(['fixture_cited_in' => relationList($cited)]), $key, relationEntry($number));
}

/**
 * The statements the call runs on the default connection.
 *
 * @param  callable(): WriteResult  $call
 * @return array{int, WriteResult}
 */
function relationStatements(callable $call): array
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

it('writes a to-one relation as a column with a foreign key that keeps its target, and a multiple one as a jsonb list', function (): void {
    $world = new EntryWorld;
    [$target] = relationTargets($world, 1);
    $article = relationEntry(90);
    expect($world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article(), 'article', $article)->outcome())->toBe(Outcome::Committed);

    $result = $world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, relationMeasurement([
        'fixture_corrects' => new TextValue($target->toString()),
        'fixture_cited_in' => relationList([$article, $target]),
    ]));
    $row = StorageTables::superuser()->table('app__fixture_measurement')->where('cms_entry_id', EntryWorld::ENTRY)->first(['fixture_corrects', 'fixture_cited_in']);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($row?->fixture_corrects)->toBe($target->toString())
        ->and(json_decode(is_string($row?->fixture_cited_in) ? $row->fixture_cited_in : '', true))->toBe([$article->toString(), $target->toString()])
        ->and(StorageTables::violation(static fn (): int => StorageTables::superuser()->table('app__fixture_measurement')->where('cms_entry_id', EntryWorld::ENTRY)->update(['fixture_corrects' => RELATION_NOWHERE])))
        ->toBe('23503 app__fixture_measurement__fixture_corrects')
        ->and(StorageTables::superuser()->scalar(<<<'SQL'
            select confdeltype::text from pg_constraint
            where conrelid = 'app__fixture_measurement'::regclass and conname = 'app__fixture_measurement__fixture_corrects'
            SQL))->toBe('r');
});

it('refuses a target that does not exist, at the field\'s path, and keeps nothing of the call', function (): void {
    $world = new EntryWorld;
    [$target] = relationTargets($world, 1);

    $result = $world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, relationMeasurement([
        'fixture_corrects' => new TextValue(RELATION_NOWHERE),
        'fixture_cited_in' => relationList([$target, EntryId::fromString(RELATION_NOWHERE)]),
        'fixture_sensor' => new GroupValue(EntryFields::map([
            'fixture_sensor_code' => new TextValue('S-7'),
            'fixture_sensor_calibration' => new TextValue(RELATION_NOWHERE),
        ])),
    ]));

    expect(relationErrors($result))->toBe([
        ['validation_relation_target_missing', 'fields.fixture_cited_in'],
        ['validation_relation_target_missing', 'fields.fixture_corrects'],
        ['validation_relation_target_missing', 'fields.fixture_sensor.fixture_sensor_calibration'],
    ])
        ->and($result->errors[1]->message)->toContain('as item 1')
        ->and(StorageTables::superuser()->table('entries')->where('id', EntryWorld::ENTRY)->count())->toBe(0);
});

it('refuses a target whose type the field does not allow, at the field\'s path', function (): void {
    $world = new EntryWorld;
    $article = relationEntry(90);
    expect($world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article(), 'article', $article)->outcome())->toBe(Outcome::Committed);

    $result = $world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, relationMeasurement([
        'fixture_corrects' => new TextValue($article->toString()),
        'fixture_cited_in' => relationList([$article]),
    ]));

    expect(relationErrors($result))->toBe([['validation_relation_target_type', 'fields.fixture_corrects']])
        ->and($result->errors[1]->message)->toContain('of the type app:fixture_article, and the field allows entries of app:fixture_measurement only');
});

it('takes the entry the command creates as a target of its own relation', function (): void {
    $result = new EntryWorld()->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, relationMeasurement([
        'fixture_corrects' => new TextValue(EntryWorld::ENTRY),
    ]));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('app__fixture_measurement')->where('cms_entry_id', EntryWorld::ENTRY)->value('fixture_corrects'))->toBe(EntryWorld::ENTRY);
});

it('reads the targets of a command in one statement: 2 targets cost the same statements as 200, refused or committed', function (): void {
    $world = new EntryWorld;
    $targets = relationTargets($world, 200);
    $type = EntryWorld::type(EntryWorld::MEASUREMENT)->id;
    $nowhere = EntryId::fromString(RELATION_NOWHERE);

    [$fewRefused, $few] = relationStatements(static fn (): WriteResult => relationCiting($world, $type, [$targets[0], $nowhere], 'few-refused', 201));
    [$manyRefused, $many] = relationStatements(static fn (): WriteResult => relationCiting($world, $type, [...array_slice($targets, 0, 199), $nowhere], 'many-refused', 202));
    [$fewCommitted, $committedFew] = relationStatements(static fn (): WriteResult => relationCiting($world, $type, array_slice($targets, 0, 2), 'few', 203));
    [$manyCommitted, $committedMany] = relationStatements(static fn (): WriteResult => relationCiting($world, $type, $targets, 'many', 204));

    expect(relationErrors($few))->toBe([['validation_relation_target_missing', 'fields.fixture_cited_in']])
        ->and(relationErrors($many))->toBe([['validation_relation_target_missing', 'fields.fixture_cited_in']])
        ->and($manyRefused)->toBe($fewRefused)
        ->and($committedFew->outcome())->toBe(Outcome::Committed)
        ->and($committedMany->outcome())->toBe(Outcome::Committed)
        ->and($manyCommitted)->toBe($fewCommitted);
});

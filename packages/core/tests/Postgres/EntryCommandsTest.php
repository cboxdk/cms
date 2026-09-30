<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Events\Boundary\EventDataJson;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryNeighbours;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * entry.create and entry.revise through the real command pipeline on Postgres (PRD 4.1, 5.4, 6.4,
 * 11.6): the entry, its variant's head, the revision with its payload for a type with full history
 * or the head snapshot for one without, and the type table's row, the draft row for stages
 * draft-release and the released row for stages none, with entry.created and variant.revised, all
 * in one changeset. The workbench's fixture_article has full history and a draft that is released;
 * fixture_measurement has neither history nor stages. A stale version is version_conflict, a home
 * the actor cannot reach and invalid fields are rejected, and nothing of a rejected call is kept.
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
function entryErrorCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * One row of a table, read as the superuser, as an array.
 *
 * @param  array<string, string>  $where
 * @return array<string, mixed>
 */
function entryRow(string $table, array $where, string ...$columns): array
{
    $row = StorageTables::superuser()->table($table)->where($where)->first($columns === [] ? ['*'] : $columns);
    $values = [];

    foreach ($row === null ? [] : get_object_vars($row) as $column => $value) {
        $values[(string) $column] = $value;
    }

    return $values;
}

/**
 * A JSON column's value, decoded to arrays.
 */
function entryJson(mixed $value): mixed
{
    return is_string($value) ? json_decode($value, true) : null;
}

/**
 * The events of the entry, oldest first, as "<type> <aggregate type> <version>".
 *
 * @return list<string>
 */
function entryEvents(): array
{
    $events = [];

    foreach (StorageTables::superuser()->select("select type || ' ' || aggregate_type || ' ' || aggregate_version as value from events order by event_id") as $row) {
        $events[] = is_object($row) && property_exists($row, 'value') && is_string($row->value) ? $row->value : '';
    }

    return $events;
}

it('creates an entry of a type with full history: the entry, the head, a draft revision with its payload and the draft row', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $result = $world->create($type->id, EntryFields::article());

    $head = entryRow('variant_heads', ['entry_id' => EntryWorld::ENTRY]);
    $revision = entryRow('revisions', ['entry_id' => EntryWorld::ENTRY]);
    $payload = entryRow('revision_payloads', ['revision_id' => is_int($revision['revision_id']) ? (string) $revision['revision_id'] : '0'], 'kind', 'format_version', 'content');
    $row = entryRow('app__fixture_article', ['cms_entry_id' => EntryWorld::ENTRY]);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(entryRow('entries', ['id' => EntryWorld::ENTRY], 'type_id', 'home_node_id', 'owner_actor_id', 'lifecycle', 'version'))->toBe([
            'type_id' => $type->id->toString(), 'home_node_id' => EntryWorld::HOME, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1,
        ])
        ->and([$head['variant'], $head['draft_revision_id'], $head['published_revision_id'], $head['schema_version'], $head['release_state'], $head['version']])
        ->toBe(['shared', $revision['revision_id'], null, $type->version, 'unreleased', 1])
        ->and([$revision['variant'], $revision['rev_no'], $revision['kind'], $revision['schema_version'], $revision['changeset_id']])
        ->toBe(['shared', 1, 'draft', $type->version, $result->receipt->changesetId?->toString()])
        ->and([$payload['kind'], $payload['format_version']])->toBe(['draft', 1])
        ->and(entryJson($payload['content']))->toEqual([
            'fixture_featured' => true,
            'fixture_published_on' => '2026-03-09',
            'fixture_reading_minutes' => 4,
            'fixture_sources' => [['fixture_source_title' => 'A "quoted" source', 'fixture_source_url' => 'https://example.org/source']],
            'fixture_title' => 'A first title',
            'fixture_topics' => ['fixture_science', 'fixture_culture'],
        ])
        ->and(StorageTables::superuser()->table('head_snapshots')->count())->toBe(0)
        ->and([$row['cms_locale'], $row['cms_stage'], $row['cms_home_node'], $row['cms_owner_actor']])->toBe(['shared', 'draft', EntryWorld::HOME, null])
        ->and([$row['fixture_title'], $row['fixture_featured'], $row['fixture_reading_minutes'], $row['fixture_published_on'], $row['fixture_topics'], $row['fixture_embargo'], $row['fixture_body']])
        ->toBe(['A first title', true, 4, '2026-03-09', '{fixture_science,fixture_culture}', null, null])
        ->and(entryJson($row['fixture_sources']))->toEqual([['fixture_source_title' => 'A "quoted" source', 'fixture_source_url' => 'https://example.org/source']])
        ->and(StorageTables::superuser()->table('app__fixture_article')->count())->toBe(1)
        ->and(entryEvents())->toBe(['entry.created entry 1', 'variant.revised variant 1']);
});

it('revises an entry of a type with full history: the next revision, the head moved to it and the draft row rewritten', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article());

    $result = $world->revise(1, EntryFields::article('A second title', false, 7));

    $head = entryRow('variant_heads', ['entry_id' => EntryWorld::ENTRY]);
    $revisions = StorageTables::superuser()->table('revisions')->orderBy('rev_no')->get(['revision_id', 'rev_no'])->map(static fn (object $row): array => (array) $row)->all();
    $row = entryRow('app__fixture_article', ['cms_entry_id' => EntryWorld::ENTRY], 'cms_stage', 'fixture_title', 'fixture_featured', 'fixture_reading_minutes');
    $event = StorageTables::superuser()->table('events')->where('type', 'variant.revised')->where('aggregate_version', 2)->value('data');
    $data = EventDataJson::decode(is_string($event) ? $event : '{}');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(array_column($revisions, 'rev_no'))->toBe([1, 2])
        ->and([$head['draft_revision_id'], $head['version']])->toBe([$revisions[1]['revision_id'], 2])
        ->and(entryRow('entries', ['id' => EntryWorld::ENTRY], 'version'))->toBe(['version' => 1])
        ->and($row)->toBe(['cms_stage' => 'draft', 'fixture_title' => 'A second title', 'fixture_featured' => false, 'fixture_reading_minutes' => 7])
        ->and(StorageTables::superuser()->table('app__fixture_article')->count())->toBe(1)
        ->and(StorageTables::superuser()->table('revision_payloads')->count())->toBe(2)
        ->and(entryEvents())->toBe(['entry.created entry 1', 'variant.revised variant 1', 'variant.revised variant 2'])
        ->and([$data->get('entry')->asIdentifier()->value, $data->get('variant')->asIdentifier()->value, $data->get('revision')->asInteger(), $data->get('previous')->asInteger()])
        ->toBe([EntryWorld::ENTRY, EntryWorld::ENTRY.':shared', 2, 1]);
});

it('creates and revises an entry of a type without history or stages: a head snapshot and the released row, no revision', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::MEASUREMENT);

    $created = $world->create($type->id, EntryFields::measurement());
    $afterCreate = entryRow('head_snapshots', ['entry_id' => EntryWorld::ENTRY], 'rev_no', 'schema_version', 'format_version');
    $revised = $world->revise(1, EntryFields::measurement('-3.5', 'south-2'), 'revise-measurement');

    $head = entryRow('variant_heads', ['entry_id' => EntryWorld::ENTRY]);
    $snapshot = entryRow('head_snapshots', ['entry_id' => EntryWorld::ENTRY]);
    $row = entryRow('app__fixture_measurement', ['cms_entry_id' => EntryWorld::ENTRY]);

    expect([$created->outcome(), $revised->outcome()])->toBe([Outcome::Committed, Outcome::Committed])
        ->and($afterCreate)->toBe(['rev_no' => 1, 'schema_version' => $type->version, 'format_version' => 1])
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('revision_payloads')->count())->toBe(0)
        ->and([$head['draft_revision_id'], $head['schema_version'], $head['release_state'], $head['version']])->toBe([null, $type->version, 'unreleased', 2])
        ->and([$snapshot['rev_no'], $snapshot['variant']])->toBe([2, 'shared'])
        ->and(entryJson($snapshot['content']))->toMatchArray(['fixture_reading' => '-3.5', 'fixture_station' => 'south-2', 'fixture_measured_at' => '2026-03-10T11:59:58.250000Z'])
        ->and([$row['cms_stage'], $row['fixture_reading'], $row['fixture_scale'], $row['fixture_station'], $row['fixture_samples'], $row['fixture_calibrated'], $row['fixture_alerts']])
        ->toBe(['released', '-3.500', 'fixture_celsius', 'south-2', 3, false, '{fixture_high}'])
        ->and(entryJson($row['fixture_sensor']))->toEqual(['fixture_sensor_code' => 'S-7'])
        ->and(entryJson($row['fixture_series']))->toBe([['fixture_series_value' => '21.1'], ['fixture_series_value' => '21.15']])
        ->and(StorageTables::superuser()->table('app__fixture_measurement')->count())->toBe(1)
        ->and(entryEvents())->toBe(['entry.created entry 1', 'variant.revised variant 1', 'variant.revised variant 2']);
});

it('keeps no draft row that holds the same values as the released row', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article('Released title'));
    $superuser = StorageTables::superuser();
    $superuser->statement("insert into app__fixture_article select cms_entry_id, cms_locale, 'released', cms_home_node, cms_owner_actor, fixture_body, fixture_embargo, fixture_featured, fixture_published_on, fixture_reading_minutes, fixture_sources, fixture_title, fixture_topics from app__fixture_article where cms_stage = 'draft'");

    $differs = $world->revise(1, EntryFields::article('A pending draft'), 'revise-differs');
    $stagesWhileDiffering = $superuser->table('app__fixture_article')->orderBy('cms_stage')->pluck('cms_stage')->all();
    $same = $world->revise(2, EntryFields::article('Released title'), 'revise-same');

    expect([$differs->outcome(), $same->outcome()])->toBe([Outcome::Committed, Outcome::Committed])
        ->and($stagesWhileDiffering)->toBe(['draft', 'released'])
        ->and($superuser->table('app__fixture_article')->pluck('cms_stage')->all())->toBe(['released'])
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(3);
});

it('rejects a create whose home node does not exist, and keeps nothing', function (): void {
    $world = new EntryWorld;

    $result = $world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article(), home: NodeId::fromString(EntryWorld::NOWHERE));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(entryErrorCodes($result))->toBe(['validation_failed', 'validation_failed'])
        ->and($result->errors[1]->message)->toContain(EntryWorld::NOWHERE)
        ->and(EntryWorld::rows())->toBe(array_fill_keys(EntryWorld::TABLES, 0));
});

it('rejects a create of an id that exists, and a revise at a stale version, with version_conflict', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $world->create($type->id, EntryFields::article());
    $before = EntryWorld::rows();

    $again = $world->create($type->id, EntryFields::article('Another'), 'create-again');
    $stale = $world->revise(2, EntryFields::article('Late'), 'revise-stale');
    $missing = $world->revise(1, EntryFields::article('Nobody'), 'revise-missing', EntryId::fromString('0192a0c0-0000-7000-8000-0000000001e9'));

    expect(entryErrorCodes($again))->toBe(['version_conflict'])
        ->and(entryErrorCodes($stale))->toBe(['version_conflict'])
        ->and(entryErrorCodes($missing))->toBe(['version_conflict'])
        ->and(EntryWorld::rows())->toBe($before)
        ->and(entryRow('app__fixture_article', ['cms_entry_id' => EntryWorld::ENTRY], 'fixture_title'))->toBe(['fixture_title' => 'A first title']);
});

it('rejects fields that break the type\'s rules, and a value for an encrypted field, and keeps nothing', function (): void {
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $invalid = $world->create($type->id, EntryFields::of(['fixture_title' => new TextValue('No flag'), 'fixture_reading_minutes' => new IntegerValue(0)]), 'create-invalid');
    $embargoed = $world->create($type->id, EntryFields::article(more: [
        'fixture_embargo' => new GroupValue(EntryFields::map(['fixture_embargo_until' => new DateTimeValue(new DateTimeImmutable('2026-03-11T08:00:00Z'))])),
    ]), 'create-embargo');
    $empty = $world->create($type->id, EntryFields::article(more: ['fixture_embargo' => new NullValue]), 'create-empty-embargo');

    expect(entryErrorCodes($invalid))->toBe(['validation_failed', 'validation_required', 'validation_below_minimum'])
        ->and(entryErrorCodes($embargoed))->toBe(['validation_failed', 'field_encryption_unavailable'])
        ->and($embargoed->errors[1]->path?->toString())->toBe('fields.fixture_embargo')
        ->and($empty->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('entries')->count())->toBe(1)
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(1);
});

it('revises an entry with the same queries whether 20 or 200 other entries are homed on its node', function (): void {
    $counts = [];

    foreach ([20, 200] as $others) {
        $world = new EntryWorld(seed: $others);
        $type = EntryWorld::type(EntryWorld::ARTICLE);
        $entry = EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 5000 + $others));
        $world->create($type->id, EntryFields::article(), 'create-'.$others, $entry);
        EntryNeighbours::seed($type->id, $others, $others * 1000);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $result = $world->revise(1, EntryFields::article('Counted'), 'revise-'.$others, $entry);
        $connection->disableQueryLog();
        $counts[$others] = [$result->outcome(), count($connection->getQueryLog())];
        $connection->flushQueryLog();
    }

    expect($counts[20][0])->toBe(Outcome::Committed)
        ->and($counts[200][0])->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('entries')->where('home_node_id', EntryWorld::HOME)->count())->toBe(222)
        ->and($counts[200][1])->toBe($counts[20][1]);
});

it('refuses to move a head to a revision that was not written', function (): void {
    $world = new EntryWorld;
    $world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article());
    $connection = DB::connection();
    $connection->beginTransaction();

    try {
        new ActorContext(app(ConnectionResolverInterface::class))->set($world->access());
        $move = static fn (): array => new HeadMovedWriter(app(ConnectionResolverInterface::class))->write(
            new HeadMoved(EntryWorld::entry(), VariantKey::shared(), new RevisionNumber(1), new RevisionNumber(9)),
            new MutationContext(ChangesetId::fromString('019cd79e-4600-7000-8000-0000000008c1'), new DateTimeImmutable(EntryWorld::NOW), $world->actor, new AggregateVersion(2)),
        );

        expect($move)->toThrow(LogicException::class, 'cannot move to revision 9');
    } finally {
        $connection->rollBack();
    }

    expect(entryRow('variant_heads', ['entry_id' => EntryWorld::ENTRY], 'version'))->toBe(['version' => 1]);
});

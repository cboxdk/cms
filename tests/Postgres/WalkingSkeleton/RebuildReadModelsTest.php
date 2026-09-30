<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\ReadModels\Actions\RebuildChunks;
use Cbox\Cms\Core\ReadModels\Adapter\PostgresReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;

/*
 * PRD 4.1, 6.5 invariant 22, 11.6, M1 exit criterion: a type table is derived, so its rows can be
 * rebuilt from the heads with the same values. Each test writes entries through the real command
 * pipeline (creates, revises and releases), reads every row of the type table as the superuser,
 * truncates the table as the owner role, rebuilds it with cms:types:rebuild's action as a service
 * actor granted the whole tree, and reads the rows again. fixture_article has full history and
 * stages draft-release, so it is rebuilt from the heads' revisions, released and draft rows both;
 * fixture_measurement has neither, so it is rebuilt from the head snapshots. A rebuild of 200
 * entries runs as several transactions, each under the 2-second budget, and a payload at another
 * schema version stops the rebuild with rebuild_schema_version_unsupported at its chunk, which a
 * second run with the same name resumes.
 */

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

function rebuildCommitted(WriteResult ...$results): void
{
    foreach ($results as $result) {
        expect($result->outcome())->toBe(Outcome::Committed);
    }
}

/**
 * The stage and title of each article row, to show which stages the series left.
 *
 * @return list<array{mixed, mixed}>
 */
function rebuildStages(): array
{
    return array_map(static fn (array $row): array => [$row['cms_stage'] ?? null, $row['fixture_title'] ?? null], RebuildWorld::rows(EntryWorld::ARTICLE));
}

it('rebuilds fixture_article from the heads\' revisions with the same values in the released and the draft stage', function (): void {
    $world = new EntryWorld;
    $rebuild = new RebuildWorld($world, chunkSize: 2);
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    // A draft only; released once; released and then revised; released, revised and released again;
    // released twice and then the older revision released back; and one with a draft equal to the released.
    $only = RebuildWorld::entry(1);
    $once = RebuildWorld::entry(2);
    $pending = RebuildWorld::entry(3);
    $twice = RebuildWorld::entry(4);
    $back = RebuildWorld::entry(5);
    $same = RebuildWorld::entry(6);

    rebuildCommitted(
        $world->create($type->id, EntryFields::article('Only a draft'), 'c1', $only),
        $world->create($type->id, EntryFields::article('Released once', featured: false), 'c2', $once),
        $world->release(1, 1, 'r2', $once),
        $world->create($type->id, EntryFields::article('Released first'), 'c3', $pending),
        $world->release(1, 1, 'r3', $pending),
        $world->revise(2, EntryFields::article('Revised since', minutes: 9), 'v3', $pending),
        $world->create($type->id, EntryFields::article('Twice, first'), 'c4', $twice),
        $world->release(1, 1, 'r4a', $twice),
        $world->revise(2, EntryFields::article('Twice, second'), 'v4', $twice),
        $world->release(3, 3, 'r4b', $twice),
        $world->create($type->id, EntryFields::article('Back, first'), 'c5', $back),
        $world->release(1, 1, 'r5a', $back),
        $world->revise(2, EntryFields::article('Back, second'), 'v5', $back),
        $world->release(3, 3, 'r5b', $back),
        $world->release(4, 2, 'r5c', $back),
        $world->create($type->id, EntryFields::article('Same again'), 'c6', $same),
        $world->release(1, 1, 'r6', $same),
        $world->revise(2, EntryFields::article('Same again'), 'v6', $same),
    );
    $before = RebuildWorld::rows(EntryWorld::ARTICLE);
    $stages = rebuildStages();

    RebuildWorld::truncate(EntryWorld::ARTICLE);
    $emptied = RebuildWorld::rows(EntryWorld::ARTICLE);
    $report = $rebuild->rebuild(EntryWorld::ARTICLE);

    expect($stages)->toBe([
        ['draft', 'Only a draft'],
        ['released', 'Released once'],
        ['draft', 'Revised since'],
        ['released', 'Released first'],
        ['released', 'Twice, second'],
        ['draft', 'Back, second'],
        ['released', 'Back, first'],
        ['released', 'Same again'],
    ])
        ->and($emptied)->toBe([])
        ->and(RebuildWorld::rows(EntryWorld::ARTICLE))->toBe($before)
        ->and($report->operation->state)->toBe(OperationState::Completed)
        ->and($report->entries())->toBe(6)
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $report->chunks))->toBe([2, 2, 2])
        ->and($report->actor->equals($rebuild->actor))->toBeTrue();

    // A second rebuild over rows that are already right changes nothing.
    $again = $rebuild->rebuild(EntryWorld::ARTICLE, 'rebuild-2');

    expect(RebuildWorld::rows(EntryWorld::ARTICLE))->toBe($before)
        ->and($again->entries())->toBe(6);
});

it('rebuilds fixture_measurement from the head snapshots with the same values', function (): void {
    $world = new EntryWorld;
    $rebuild = new RebuildWorld($world, chunkSize: 3);
    $type = EntryWorld::type(EntryWorld::MEASUREMENT);
    $results = [];

    foreach (range(1, 7) as $number) {
        $results[] = $world->create($type->id, EntryFields::measurement(sprintf('%d.125', $number), 'station-'.$number), 'c'.$number, RebuildWorld::entry($number));
    }

    foreach ([2, 5, 7] as $number) {
        $results[] = $world->revise(1, EntryFields::measurement(sprintf('%d.500', 10 + $number), 'moved-'.$number), 'v'.$number, RebuildWorld::entry($number));
    }

    rebuildCommitted(...$results);
    $before = RebuildWorld::rows(EntryWorld::MEASUREMENT);

    RebuildWorld::truncate(EntryWorld::MEASUREMENT);
    $report = $rebuild->rebuild(EntryWorld::MEASUREMENT);

    expect($before)->toHaveCount(7)
        ->and(array_values(array_unique(array_map(static fn (array $row): string => is_string($row['cms_stage'] ?? null) ? $row['cms_stage'] : '', $before))))->toBe(['released'])
        ->and(array_map(static fn (array $row): mixed => $row['fixture_station'] ?? null, $before))->toBe(['station-1', 'moved-2', 'station-3', 'station-4', 'moved-5', 'station-6', 'moved-7'])
        ->and(RebuildWorld::rows(EntryWorld::MEASUREMENT))->toBe($before)
        ->and($report->operation->state)->toBe(OperationState::Completed)
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $report->chunks))->toBe([3, 3, 1])
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(0);
});

it('rebuilds 200 entries in several transactions, each under the chunk budget', function (): void {
    $world = new EntryWorld;
    $rebuild = new RebuildWorld($world, chunkSize: 50);
    $type = EntryWorld::type(EntryWorld::MEASUREMENT);

    foreach (range(1, 200) as $number) {
        rebuildCommitted($world->create($type->id, EntryFields::measurement(sprintf('%d.250', $number), 'station-'.$number), 'c'.$number, RebuildWorld::entry($number)));
    }

    $before = RebuildWorld::rows(EntryWorld::MEASUREMENT);
    RebuildWorld::truncate(EntryWorld::MEASUREMENT);

    $begun = 0;
    Event::listen(TransactionBeginning::class, static function () use (&$begun): void {
        $begun++;
    });
    $report = $rebuild->rebuild(EntryWorld::MEASUREMENT);

    expect(RebuildWorld::rows(EntryWorld::MEASUREMENT))->toBe($before)
        ->and($before)->toHaveCount(200)
        ->and($report->entries())->toBe(200)
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $report->chunks))->toBe([50, 50, 50, 50])
        ->and(array_filter($report->chunks, static fn (ChunkResult $chunk): bool => $chunk->milliseconds >= PostgresReadModelStore::TRANSACTION_MILLISECONDS))->toBe([])
        ->and($report->longestMilliseconds())->toBeLessThan(PostgresReadModelStore::TRANSACTION_MILLISECONDS)
        ->and($report->operation->completedNames())->toHaveCount(4)
        // One transaction for the access context, five to plan (four full ranges and the empty read
        // after them), the runner's start, and per chunk its own and the runner's record of it, and
        // the completion: each chunk commits on its own.
        ->and($begun)->toBe(1 + 5 + 1 + 4 * 2 + 1);
});

it('stops at a payload at another schema version with rebuild_schema_version_unsupported, keeps the chunks before it, and resumes there', function (string $typeName, string $table, string $column): void {
    $world = new EntryWorld;
    $rebuild = new RebuildWorld($world, chunkSize: 2);
    $type = EntryWorld::type($typeName);

    foreach (range(1, 5) as $number) {
        rebuildCommitted($world->create(
            $type->id,
            $typeName === EntryWorld::ARTICLE ? EntryFields::article('Article '.$number) : EntryFields::measurement(sprintf('%d.750', $number)),
            'c'.$number,
            RebuildWorld::entry($number),
        ));
    }

    $before = RebuildWorld::rows($typeName);
    $superuser = StorageTables::superuser();
    $superuser->table($table)->where($column, RebuildWorld::entry(3)->toString())->update(['schema_version' => $type->version + 1]);
    RebuildWorld::truncate($typeName);

    $refused = null;

    try {
        $rebuild->rebuild($typeName, 'stopped');
    } catch (RebuildRefused $exception) {
        $refused = $exception;
    }

    $stopped = app(OperationRunner::class)->find(new OperationKind(RebuildChunks::KIND), new RebuildRequest(new TypeName($typeName), 'stopped')->key);
    $kept = array_map(static fn (array $row): mixed => $row['cms_entry_id'] ?? null, RebuildWorld::rows($typeName));

    $superuser->table($table)->where($column, RebuildWorld::entry(3)->toString())->update(['schema_version' => $type->version]);
    $resumed = $rebuild->rebuild($typeName, 'stopped');

    expect($refused)->toBeInstanceOf(RebuildRefused::class)
        ->and($refused?->errorCode)->toBe('rebuild_schema_version_unsupported')
        ->and($refused?->getMessage())->toContain(RebuildWorld::entry(3)->toString())
        ->and($stopped?->state)->toBe(OperationState::Running)
        ->and($stopped?->completed)->toHaveCount(1)
        ->and($kept)->toBe([RebuildWorld::entry(1)->toString(), RebuildWorld::entry(2)->toString()])
        ->and($resumed->operation->state)->toBe(OperationState::Completed)
        ->and($stopped !== null && $resumed->operation->id->equals($stopped->id))->toBeTrue()
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $resumed->chunks))->toBe([2, 1])
        ->and(RebuildWorld::rows($typeName))->toBe($before);
})->with([
    'a revision of fixture_article' => [EntryWorld::ARTICLE, 'revisions', 'entry_id'],
    'a head snapshot of fixture_measurement' => [EntryWorld::MEASUREMENT, 'head_snapshots', 'entry_id'],
]);

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Doctor\Adapter\CatalogPartitionRunwayProbe;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Tests\Partitions\Fakes\FakePartitionMaintenance;
use DateTimeImmutable;
use ReflectionClass;

/*
 * The runway of a managed table (PRD 4, 4.2): the unbroken run of attached partitions from now,
 * or from the sequence's current value for a table partitioned on a sequence, which the partition
 * manager's report and the doctor's partitions.runway check both measure with PartitionRunway.
 */

function runwayTable(PartitionInterval $interval = PartitionInterval::Day): PartitionedTable
{
    return new PartitionedTable('events', PartitionKey::Uuid7, $interval, null);
}

/**
 * The daily partitions of the events table that start on the given dates.
 *
 * @param  list<string>  $dates
 * @return list<Partition>
 */
function dailyPartitions(array $dates): array
{
    return array_map(static fn (string $date): Partition => runwayTable()->partitionAt(new DateTimeImmutable($date.'T00:00:00Z')), $dates);
}

/**
 * The runway from $now over the daily partitions that start on $dates, as an ISO 8601 time.
 *
 * @param  list<string>  $dates
 */
function runwayEnd(array $dates, string $now): ?string
{
    return PartitionRunway::end(runwayTable(), dailyPartitions($dates), new DateTimeImmutable($now))?->format(DATE_ATOM);
}

it('ends at the end of the last partition of an unbroken run from now', function (): void {
    expect(runwayEnd(['2026-01-01', '2026-01-02', '2026-01-03'], '2026-01-01T10:00:00Z'))->toBe('2026-01-04T00:00:00+00:00')
        ->and(runwayEnd(['2026-01-01', '2026-01-02', '2026-01-03'], '2026-01-03T23:59:59.999999Z'))->toBe('2026-01-04T00:00:00+00:00');
});

it('ends at the first gap, however far the partitions after it reach', function (): void {
    expect(runwayEnd(['2026-01-01', '2026-01-02', '2026-01-04', '2026-01-05', '2026-03-01'], '2026-01-01T10:00:00Z'))->toBe('2026-01-03T00:00:00+00:00')
        ->and(runwayEnd(['2026-01-01', '2026-01-02', '2026-01-04', '2026-01-05'], '2026-01-04T00:00:00Z'))->toBe('2026-01-06T00:00:00+00:00');
});

it('is null when no partition holds now, even with partitions before and after it', function (): void {
    expect(runwayEnd([], '2026-01-01T10:00:00Z'))->toBeNull()
        ->and(runwayEnd(['2025-12-31', '2026-01-02', '2026-01-03'], '2026-01-01T10:00:00Z'))->toBeNull()
        ->and(runwayEnd(['2026-01-01'], '2026-01-02T00:00:00Z'))->toBeNull();
});

it('takes the partitions in any order and counts a duplicate once', function (): void {
    expect(runwayEnd(['2026-01-03', '2026-01-01', '2026-01-02', '2026-01-01'], '2026-01-01T10:00:00Z'))->toBe('2026-01-04T00:00:00+00:00');
});

it('follows the table\'s interval across month ends', function (): void {
    $table = runwayTable(PartitionInterval::Month);
    $attached = array_map(
        static fn (string $month): Partition => $table->partitionAt(new DateTimeImmutable($month.'-01T00:00:00Z')),
        ['2026-01', '2026-02', '2026-04'],
    );

    expect(PartitionRunway::end($table, $attached, new DateTimeImmutable('2026-01-31T23:00:00Z'))?->format(DATE_ATOM))->toBe('2026-03-01T00:00:00+00:00');
});

it('is the one computation of the partition manager\'s report, the doctor\'s probe and the fake manager', function (string $file): void {
    $source = (string) file_get_contents($file);

    expect(substr_count($source, 'PartitionRunway::end('))->toBe(1)
        ->and($source)->not->toContain('max(');
})->with([
    'the manager\'s report' => [(string) new ReflectionClass(PostgresPartitionManager::class)->getFileName()],
    'the doctor\'s partitions.runway probe' => [(string) new ReflectionClass(CatalogPartitionRunwayProbe::class)->getFileName()],
    'the fake manager' => [(string) new ReflectionClass(FakePartitionMaintenance::class)->getFileName()],
]);

/**
 * The runway ahead of $current over the partitions of 100 ids of the events table that start at
 * $lowers, as "until <end or none>, <partitions> ahead".
 *
 * @param  list<int>  $lowers
 */
function sequenceRunway(array $lowers, int $current): string
{
    $table = new SequencePartitionedTable('events', 100, 'events_event_id_seq');
    $runway = PartitionRunway::ahead($table, array_map($table->partitionHolding(...), $lowers), $current);

    expect($runway->current)->toBe($current);

    return sprintf('until %s, %d ahead', $runway->coveredUntil ?? 'none', $runway->partitionsAhead);
}

it('counts the empty partitions ahead of the sequence\'s current value in an unbroken run', function (): void {
    expect(sequenceRunway([0, 100, 200, 300], 150))->toBe('until 400, 2 ahead')
        ->and(sequenceRunway([0, 100, 200, 300], 199))->toBe('until 400, 2 ahead')
        ->and(sequenceRunway([0, 100, 200, 300], 200))->toBe('until 400, 1 ahead')
        ->and(sequenceRunway([300, 100, 200, 100], 150))->toBe('until 400, 2 ahead');
});

it('ends the sequence runway at the first gap, however far the partitions after it reach', function (): void {
    expect(sequenceRunway([100, 200, 400, 500, 900], 150))->toBe('until 300, 1 ahead')
        ->and(sequenceRunway([100, 300], 150))->toBe('until 200, 0 ahead');
});

it('has no sequence runway when no partition holds the current value, even with partitions after it', function (): void {
    expect(sequenceRunway([], 150))->toBe('until none, 0 ahead')
        ->and(sequenceRunway([0, 200, 300], 150))->toBe('until none, 0 ahead')
        ->and(sequenceRunway([100], 200))->toBe('until none, 0 ahead');
});

it('counts the first partition as ahead before the sequence hands out its first id', function (): void {
    expect(sequenceRunway([0, 100], -1))->toBe('until 200, 2 ahead')
        ->and(sequenceRunway([0, 100], 0))->toBe('until 200, 1 ahead');
});

it('starts the sequence runway at the partition that holds the current value, even one id wide', function (): void {
    $table = new SequencePartitionedTable('events', 1, 'events_event_id_seq');
    $runway = PartitionRunway::ahead($table, [$table->partitionHolding(0)], 0);

    expect([$runway->coveredUntil, $runway->partitionsAhead])->toBe([1, 0]);
});

it('ends the sequence runway at the largest bigint', function (): void {
    $table = new SequencePartitionedTable('events', 1_000_000_000_000_000_000, 'events_event_id_seq');
    $runway = PartitionRunway::ahead($table, $table->runway(8_500_000_000_000_000_000, 5), 8_500_000_000_000_000_000);

    expect($runway->coveredUntil)->toBe(PHP_INT_MAX)
        ->and($runway->partitionsAhead)->toBe(1);
});

it('is the one sequence computation of the partition manager\'s report, the doctor\'s probe and the fake manager', function (string $file): void {
    expect(substr_count((string) file_get_contents($file), 'PartitionRunway::ahead('))->toBe(1);
})->with([
    'the manager\'s report' => [(string) new ReflectionClass(PostgresPartitionManager::class)->getFileName()],
    'the doctor\'s partitions.runway probe' => [(string) new ReflectionClass(CatalogPartitionRunwayProbe::class)->getFileName()],
    'the fake manager' => [(string) new ReflectionClass(FakePartitionMaintenance::class)->getFileName()],
]);

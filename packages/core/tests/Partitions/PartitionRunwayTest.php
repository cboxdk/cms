<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Doctor\Adapter\CatalogPartitionRunwayProbe;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Tests\Partitions\Fakes\FakePartitionMaintenance;
use DateTimeImmutable;
use ReflectionClass;

/*
 * The runway of a managed table (PRD 4, 4.2): the unbroken run of attached partitions from now,
 * which the partition manager's report and the doctor's partitions.runway check both measure with
 * PartitionRunway.
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

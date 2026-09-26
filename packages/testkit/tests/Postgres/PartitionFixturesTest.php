<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Core\Tests\Postgres\PartitionScratch;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The partition fixture helper: a test at any FakeClock date creates the partitions it writes to.
 */

beforeEach(function (): void {
    PartitionScratch::create();
    PartitionScratch::manage([
        PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 1]),
        PartitionScratch::TIME_TABLE => PartitionScratch::daily(['key' => 'timestamp', 'interval' => 'month', 'retention_days' => 1]),
    ]);
});

afterEach(function (): void {
    PartitionScratch::drop();
});

it('covers a FakeClock date range for every managed table, so writes at those dates work', function (string $date): void {
    $clock = new FakeClock(new DateTimeImmutable($date));
    $ids = new FakeIdGenerator(clock: $clock);

    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P2D'));

    $day = new DateTimeImmutable($date);
    $expected = array_map(
        static fn (int $offset): string => 'partition_scratch_p'.$day->modify(sprintf('+%d days', $offset))->format('Ymd'),
        [0, 1, 2],
    );

    $clock->advance(new DateInterval('P1DT3H'));
    $id = $ids->next()->value;
    PartitionScratch::app()->insert('insert into partition_scratch (id) values (?)', [$id]);
    PartitionScratch::app()->insert('insert into partition_scratch_ts (at) values (?)', [$clock->now()->format('Y-m-d H:i:s.uP')]);

    expect(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe($expected)
        ->and(PartitionScratch::partitionOfId($id))->toBe('partition_scratch_p'.$clock->now()->format('Ymd'))
        ->and(PartitionScratch::partitions(PartitionScratch::TIME_TABLE))->not->toBe([]);
})->with([
    'the FakeClock start' => [FakeClock::START],
    'far ahead' => ['2031-05-01T09:00:00Z'],
    'in the past' => ['1999-12-31T23:00:00Z'],
]);

it('covers an explicit range and removes nothing, even partitions past retention', function (): void {
    $fixtures = app(PartitionFixtures::class);

    $fixtures->cover(new DateTimeImmutable('2020-01-01T00:00:00Z'), new DateTimeImmutable('2020-01-01T23:59:59Z'));
    $fixtures->cover(new DateTimeImmutable('2020-06-01T00:00:00+02:00'), new DateTimeImmutable('2020-06-01T00:00:00+02:00'));

    expect(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe(['partition_scratch_p20200101', 'partition_scratch_p20200531'])
        ->and(PartitionScratch::partitions(PartitionScratch::TIME_TABLE))->toBe(['partition_scratch_ts_p202001', 'partition_scratch_ts_p202005']);
});

it('fails the test with the command output when the partitions cannot be made', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['owner_connection' => 'pgsql']);

    expect(static fn () => app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02')))
        ->toThrow(AssertionFailedError::class, 'cms:partitions:maintain --from=2026-01-01T00:00:00.000000+00:00 --to=2026-01-02T00:00:00.000000+00:00 failed with exit code 78.');
});

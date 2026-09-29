<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\SequencePartition;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequenceRetention;
use DateTimeImmutable;
use InvalidArgumentException;

/*
 * A table partitioned on a bigint that a sequence feeds (PRD 4.1, 7.2): partitions of a fixed
 * width in ids, named by their zero-padded lower bound, a runway of empty partitions ahead of the
 * sequence's current value, and retention by the newest row once the sequence has passed a
 * partition.
 */

function sequenceTable(int $width = 1000, ?int $retentionDays = null): SequencePartitionedTable
{
    return new SequencePartitionedTable(
        'events',
        $width,
        'events_event_id_seq',
        $retentionDays === null ? null : new SequenceRetention('events', $retentionDays, 'occurred_at'),
    );
}

/**
 * @param  list<SequencePartition>  $partitions
 * @return list<string>
 */
function sequenceNames(array $partitions): array
{
    return array_map(static fn (SequencePartition $partition): string => sprintf('%s [%d, %d)', $partition->name, $partition->lower, $partition->upper), $partitions);
}

it('names a partition by its lower bound zero-padded to the digits of the largest bigint', function (): void {
    $partition = sequenceTable()->partitionHolding(1999);

    expect($partition->name)->toBe('events_p0000000000000001000')
        ->and($partition->lower)->toBe(1000)
        ->and($partition->upper)->toBe(2000)
        ->and($partition->from())->toBe('1000')
        ->and($partition->to())->toBe('2000')
        ->and(sequenceTable()->partitionHolding(2000)->name)->toBe('events_p0000000000000002000')
        ->and(sequenceTable()->partitionHolding(0)->name)->toBe('events_p0000000000000000000')
        ->and(sequenceTable(7)->partitionHolding(20)->lower)->toBe(14);
});

it('ends the last partition at the largest bigint instead of overflowing', function (): void {
    $last = sequenceTable(1_000_000_000_000_000_000)->partitionHolding(PHP_INT_MAX - 1);

    expect($last->lower)->toBe(9_000_000_000_000_000_000)
        ->and($last->upper)->toBe(PHP_INT_MAX)
        ->and($last->name)->toBe('events_p9000000000000000000')
        ->and(sequenceTable(1_000_000_000_000_000_000)->partitionHolding(PHP_INT_MAX)->name)->toBe($last->name)
        ->and(sequenceNames(sequenceTable(1_000_000_000_000_000_000)->runway(8_500_000_000_000_000_000, 3)))->toBe([
            'events_p8000000000000000000 [8000000000000000000, 9000000000000000000)',
            'events_p9000000000000000000 [9000000000000000000, 9223372036854775807)',
        ]);
});

it('refuses an id below 0, which has no partition', function (): void {
    expect(static fn (): SequencePartition => sequenceTable()->partitionHolding(-1))
        ->toThrow(InvalidArgumentException::class, 'The id -1 of table "events" is below 0');
});

it('makes the runway from the partition that holds the current value to the empty partitions ahead of it', function (): void {
    expect(sequenceNames(sequenceTable()->runway(1500, 2)))->toBe([
        'events_p0000000000000001000 [1000, 2000)',
        'events_p0000000000000002000 [2000, 3000)',
        'events_p0000000000000003000 [3000, 4000)',
    ])
        // The last id of a partition leaves it full: the runway still has two empty ones after it.
        ->and(sequenceNames(sequenceTable()->runway(1999, 1)))->toBe(['events_p0000000000000001000 [1000, 2000)', 'events_p0000000000000002000 [2000, 3000)'])
        ->and(sequenceNames(sequenceTable()->runway(0, 1)))->toBe(['events_p0000000000000000000 [0, 1000)', 'events_p0000000000000001000 [1000, 2000)'])
        ->and(sequenceNames(sequenceTable(1)->runway(0, 1)))->toBe(['events_p0000000000000000000 [0, 1)', 'events_p0000000000000000001 [1, 2)']);
});

it('counts the first partition as ahead before the sequence has handed out an id', function (): void {
    expect(sequenceNames(sequenceTable()->runway(-1, 2)))->toBe([
        'events_p0000000000000000000 [0, 1000)',
        'events_p0000000000000001000 [1000, 2000)',
    ])
        ->and(sequenceTable()->partitionHolding(0)->isAhead(-1))->toBeTrue()
        ->and(sequenceTable()->partitionHolding(0)->isAhead(0))->toBeFalse()
        ->and(sequenceTable()->partitionHolding(1000)->isAhead(999))->toBeTrue();
});

it('reads its own partition names back and ignores every other name', function (): void {
    expect(sequenceTable()->partitionNamed('events_p0000000000000002000')?->upper)->toBe(3000)
        ->and(sequenceTable(1_000_000_000_000_000_000)->partitionNamed('events_p9000000000000000000')?->upper)->toBe(PHP_INT_MAX);

    foreach ([
        'events_p0000000000000002500',
        'events_p000000000000002000',
        'events_p00000000000000002000',
        'events_p000000000000000200x',
        'events_p-000000000000002000',
        'events_p9223372036854776000',
        'events_p9999999999999999999',
        'other_p0000000000000002000',
        'events_x_p0000000000000002000',
        'events_old',
    ] as $name) {
        expect(sequenceTable()->partitionNamed($name))->toBeNull();
    }

    expect(sequenceTable(1)->partitionNamed('events_p9223372036854775806')?->lower)->toBe(PHP_INT_MAX - 1)
        ->and(sequenceTable(1)->partitionNamed('events_p9223372036854775807'))->toBeNull()
        ->and(sequenceTable(1)->partitionNamed('events_p9223372036854775808'))->toBeNull()
        // The prefix is the table's own, not just a string of its length.
        ->and(sequenceTable()->partitionNamed('evenzs_p0000000000000002000'))->toBeNull();
});

it('is passed once the sequence has handed out an id at or after its upper bound', function (): void {
    $partition = sequenceTable()->partitionHolding(1000);

    expect($partition->isPassed(1999))->toBeFalse()
        ->and($partition->isPassed(2000))->toBeTrue()
        ->and($partition->isPassed(5000))->toBeTrue();
});

it('expires a passed partition once its newest row is the retention old, and never without retention', function (): void {
    $partition = sequenceTable(retentionDays: 30)->partitionHolding(1000);
    $newest = new DateTimeImmutable('2026-01-01T12:00:00Z');

    expect($partition->isExpiredAt(2000, $newest, new DateTimeImmutable('2026-01-31T11:59:59.999999Z')))->toBeFalse()
        ->and($partition->isExpiredAt(2000, $newest, new DateTimeImmutable('2026-01-31T12:00:00Z')))->toBeTrue()
        ->and($partition->isExpiredAt(1999, $newest, new DateTimeImmutable('2027-01-01T00:00:00Z')))->toBeFalse()
        ->and($partition->isExpiredAt(2000, null, new DateTimeImmutable('2026-01-01T00:00:00Z')))->toBeTrue()
        ->and($partition->isExpiredAt(1999, null, new DateTimeImmutable('2026-01-01T00:00:00Z')))->toBeFalse()
        ->and(sequenceTable()->partitionHolding(1000)->isExpiredAt(PHP_INT_MAX, null, new DateTimeImmutable('2100-01-01T00:00:00Z')))->toBeFalse();
});

it('refuses names Postgres cannot hold with a partition suffix, a width under 1 and retention without a usable column', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidPartitionPolicy::class, $message);
})->with([
    'long name' => [static fn (): SequencePartitionedTable => new SequencePartitionedTable(str_repeat('a', 43), 1000, 'ids'), 'at most 42 characters'],
    'upper case' => [static fn (): SequencePartitionedTable => new SequencePartitionedTable('Events', 1000, 'ids'), 'is not valid'],
    'no width' => [static fn (): SequencePartitionedTable => new SequencePartitionedTable('events', 0, 'ids'), 'The partition width of table "events" is 0 ids'],
    'schema in the sequence' => [static fn (): SequencePartitionedTable => new SequencePartitionedTable('events', 1000, 'cms.ids'), 'The sequence "cms.ids" of table "events" is not a valid name'],
    'long sequence' => [static fn (): SequencePartitionedTable => new SequencePartitionedTable('events', 1000, str_repeat('s', 64)), 'is not a valid name'],
    'no retention' => [static fn (): SequenceRetention => new SequenceRetention('events', 0, 'occurred_at'), 'Use at least 1 day'],
    'bad column' => [static fn (): SequenceRetention => new SequenceRetention('events', 30, 'Occurred At'), 'The retention column "Occurred At" of table "events"'],
    'long column' => [static fn (): SequenceRetention => new SequenceRetention('events', 30, str_repeat('c', 64)), 'is not a valid column name'],
]);

it('takes names at the longest Postgres holds', function (): void {
    expect(new SequencePartitionedTable(str_repeat('a', 42), 1, str_repeat('s', 63))->partitionHolding(5)->name)->toHaveLength(63)
        ->and(new SequenceRetention('events', 1, str_repeat('c', 63))->column)->toHaveLength(63);
});

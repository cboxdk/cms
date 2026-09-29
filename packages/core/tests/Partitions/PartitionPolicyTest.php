<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;

it('defaults to a 14-day runway, lock_timeout 2s and three attempts', function (): void {
    $policy = new PartitionPolicy('pgsql_owner', []);

    expect($policy->runwayDays)->toBe(14)
        ->and($policy->lockTimeoutSetting())->toBe('2s')
        ->and($policy->attempts)->toBe(3)
        ->and(new PartitionPolicy('pgsql_owner', [], lockTimeoutMs: 1500)->lockTimeoutSetting())->toBe('1500ms');
});

it('waits nothing before the first attempt and doubles the backoff after', function (): void {
    $policy = new PartitionPolicy('pgsql_owner', [], backoffMs: 250);

    expect(array_map($policy->backoffBefore(...), [1, 2, 3, 4]))->toBe([0, 250, 500, 1000]);
});

it('refuses values outside their range and a table listed twice', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidPartitionPolicy::class, $message);
})->with([
    'no connection' => [static fn (): PartitionPolicy => new PartitionPolicy('', []), '[cbox-cms.database.owner_connection]'],
    'no runway' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], runwayDays: 0), '[cbox-cms.database.partitions.runway_days] is "0"'],
    'long runway' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], runwayDays: 367), 'from 1 to 366'],
    'no timeout' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], lockTimeoutMs: 0), 'partitions.lock_timeout_ms'],
    'no attempts' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], attempts: 0), 'partitions.attempts'],
    'many attempts' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], attempts: 11), 'partitions.attempts'],
    'negative backoff' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [], backoffMs: -1), 'partitions.backoff_ms'],
    'twice' => [static fn (): PartitionPolicy => new PartitionPolicy('o', [
        new PartitionedTable('receipts', PartitionKey::Uuid7, PartitionInterval::Day, 7),
        new PartitionedTable('receipts', PartitionKey::Uuid7, PartitionInterval::Month, 7),
    ]), 'names a table twice: receipts'],
]);

it('keeps two empty partitions ahead of a sequence by default and refuses a runway outside 1 to 100 partitions', function (): void {
    expect(new PartitionPolicy('o', [])->runwayPartitions)->toBe(2)
        ->and(new PartitionPolicy('o', [], runwayPartitions: 1)->runwayPartitions)->toBe(1)
        ->and(new PartitionPolicy('o', [], runwayPartitions: 100)->runwayPartitions)->toBe(100)
        ->and(static fn (): PartitionPolicy => new PartitionPolicy('o', [], runwayPartitions: 0))
        ->toThrow(InvalidPartitionPolicy::class, '[cbox-cms.database.partitions.runway_partitions] is "0". Use from 1 to 100.')
        ->and(static fn (): PartitionPolicy => new PartitionPolicy('o', [], runwayPartitions: 101))
        ->toThrow(InvalidPartitionPolicy::class, 'is "101"');
});

it('refuses a name listed as a table on time and as a table on a sequence', function (): void {
    expect(static fn (): PartitionPolicy => new PartitionPolicy(
        'o',
        [new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, 7)],
        sequenceTables: [new SequencePartitionedTable('events', 1000, 'ids'), new SequencePartitionedTable('payloads', 1000, 'ids')],
    ))->toThrow(InvalidPartitionPolicy::class, 'names a table twice: events.')
        ->and(static fn (): PartitionPolicy => new PartitionPolicy('o', [], sequenceTables: [
            new SequencePartitionedTable('payloads', 1000, 'ids'),
            new SequencePartitionedTable('payloads', 10, 'other_ids'),
        ]))->toThrow(InvalidPartitionPolicy::class, 'names a table twice: payloads.');
});

it('takes every value at the edges of its range', function (): void {
    $lowest = new PartitionPolicy('o', [], runwayDays: 1, lockTimeoutMs: 1, attempts: 1, backoffMs: 0, runwayPartitions: 1);
    $highest = new PartitionPolicy('o', [], runwayDays: 366, lockTimeoutMs: 60_000, attempts: 10, backoffMs: 60_000, runwayPartitions: 100);

    expect([$lowest->runwayDays, $lowest->lockTimeoutMs, $lowest->attempts, $lowest->backoffMs, $lowest->runwayPartitions])->toBe([1, 1, 1, 0, 1])
        ->and([$highest->runwayDays, $highest->lockTimeoutMs, $highest->attempts, $highest->backoffMs, $highest->runwayPartitions])->toBe([366, 60_000, 10, 60_000, 100])
        ->and(static fn (): PartitionPolicy => new PartitionPolicy('o', [], lockTimeoutMs: 60_001))->toThrow(InvalidPartitionPolicy::class, 'partitions.lock_timeout_ms')
        ->and(static fn (): PartitionPolicy => new PartitionPolicy('o', [], backoffMs: 60_001))->toThrow(InvalidPartitionPolicy::class, 'partitions.backoff_ms');
});

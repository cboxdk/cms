<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;

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

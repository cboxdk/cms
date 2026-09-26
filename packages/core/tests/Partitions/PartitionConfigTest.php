<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Illuminate\Config\Repository;

it('reads the package defaults: the pgsql_owner connection, a 14-day runway and the receipt tables', function (): void {
    $policy = PartitionConfig::read(config());

    expect($policy->ownerConnection)->toBe('pgsql_owner')
        ->and($policy->runwayDays)->toBe(14)
        ->and($policy->lockTimeoutMs)->toBe(2000)
        ->and($policy->attempts)->toBe(3)
        ->and($policy->backoffMs)->toBe(250)
        ->and(array_map(
            static fn (PartitionedTable $table): string => sprintf('%s %s %s %s', $table->name, $table->key->value, $table->interval->value, $table->retentionDays ?? 'keep'),
            $policy->tables,
        ))->toBe([
            'receipts_standard uuid7 day 7',
            'receipts_evidence uuid7 month keep',
            'receipt_projections_standard uuid7 day 7',
            'receipt_projections_evidence uuid7 month keep',
        ]);
});

it('reads each table with its key, interval and retention', function (): void {
    $policy = PartitionConfig::read(new Repository(['cms' => ['database' => [
        'owner_connection' => 'owner',
        'partitions' => [
            'runway_days' => 30,
            'tables' => [
                'receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
                'audit' => ['key' => 'timestamp', 'interval' => 'month', 'retention_days' => null],
            ],
        ],
    ]]]));

    expect($policy->runwayDays)->toBe(30)
        ->and($policy->attempts)->toBe(3)
        ->and($policy->tables)->toHaveCount(2)
        ->and($policy->tables[0]->name)->toBe('receipts')
        ->and($policy->tables[0]->key)->toBe(PartitionKey::Uuid7)
        ->and($policy->tables[0]->retentionDays)->toBe(7)
        ->and($policy->tables[1]->interval)->toBe(PartitionInterval::Month)
        ->and($policy->tables[1]->retentionDays)->toBeNull();
});

it('names the setting that is wrong', function (mixed $database, string $message): void {
    expect(static fn (): PartitionPolicy => PartitionConfig::read(new Repository(['cms' => ['database' => $database]])))
        ->toThrow(InvalidPartitionPolicy::class, $message);
})->with([
    'no owner' => [[], '[cms.database.owner_connection] is "null"'],
    'tables not a map' => [['owner_connection' => 'o', 'partitions' => ['tables' => 'receipts']], '[cms.database.partitions.tables]'],
    'a list of names' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts']]], '[cms.database.partitions.tables.0]'],
    'unknown key' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid4', 'interval' => 'day']]]], '[cms.database.partitions.tables.receipts.key] is "uuid4"'],
    'unknown interval' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid7', 'interval' => 'week']]]], '[cms.database.partitions.tables.receipts.interval] is "week"'],
    'retention as text' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => '7']]]], '[cms.database.partitions.tables.receipts.retention_days] is "7"'],
    'runway as text' => [['owner_connection' => 'o', 'partitions' => ['runway_days' => '14']], '[cms.database.partitions.runway_days] is "14"'],
]);

it('binds partition maintenance to the Postgres manager, built from the configuration on each resolution', function (): void {
    expect(app(PartitionMaintenance::class))->toBeInstanceOf(PostgresPartitionManager::class)
        ->and(app(PartitionMaintenance::class))->not->toBe(app(PartitionMaintenance::class));

    config()->set('cms.database.partitions.runway_days', 0);

    expect(static fn (): PartitionMaintenance => app(PartitionMaintenance::class))
        ->toThrow(InvalidPartitionPolicy::class, 'runway_days');
});

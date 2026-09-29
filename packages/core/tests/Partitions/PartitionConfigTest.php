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
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequenceRetention;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Illuminate\Config\Repository;

it('reads the package defaults: the pgsql_owner connection, a 14-day runway, the receipt tables and the idempotency table', function (): void {
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
            'idempotency_keys timestamp day 7',
        ]);
});

it('reads each table with its key, interval and retention', function (): void {
    $policy = PartitionConfig::read(new Repository(['cbox-cms' => ['database' => [
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
    expect(static fn (): PartitionPolicy => PartitionConfig::read(new Repository(['cbox-cms' => ['database' => $database]])))
        ->toThrow(InvalidPartitionPolicy::class, $message);
})->with([
    'no owner' => [[], '[cbox-cms.database.owner_connection] is "null"'],
    'tables not a map' => [['owner_connection' => 'o', 'partitions' => ['tables' => 'receipts']], '[cbox-cms.database.partitions.tables]'],
    'a list of names' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts']]], '[cbox-cms.database.partitions.tables.0]'],
    'unknown key' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid4', 'interval' => 'day']]]], '[cbox-cms.database.partitions.tables.receipts.key] is "uuid4"'],
    'unknown interval' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid7', 'interval' => 'week']]]], '[cbox-cms.database.partitions.tables.receipts.interval] is "week"'],
    'retention as text' => [['owner_connection' => 'o', 'partitions' => ['tables' => ['receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => '7']]]], '[cbox-cms.database.partitions.tables.receipts.retention_days] is "7"'],
    'runway as text' => [['owner_connection' => 'o', 'partitions' => ['runway_days' => '14']], '[cbox-cms.database.partitions.runway_days] is "14"'],
]);

it('binds partition maintenance to the Postgres manager, built from the configuration on each resolution', function (): void {
    expect(app(PartitionMaintenance::class))->toBeInstanceOf(PostgresPartitionManager::class)
        ->and(app(PartitionMaintenance::class))->not->toBe(app(PartitionMaintenance::class));

    config()->set('cbox-cms.database.partitions.runway_days', 0);

    expect(static fn (): PartitionMaintenance => app(PartitionMaintenance::class))
        ->toThrow(InvalidPartitionPolicy::class, 'runway_days');
});

it('reads a table with a bigint key into the tables on a sequence, with its width, sequence and retention', function (): void {
    $policy = PartitionConfig::read(new Repository(['cbox-cms' => ['database' => [
        'owner_connection' => 'owner',
        'partitions' => [
            'runway_partitions' => 3,
            'tables' => [
                'events' => ['key' => 'bigint', 'width' => 1_000_000, 'sequence' => 'events_event_id_seq', 'retention_days' => 30, 'retention_column' => 'occurred_at'],
                'receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
                'revision_payloads_published' => ['key' => 'bigint', 'width' => 10_000_000, 'sequence' => 'revisions_revision_id_seq'],
            ],
        ],
    ]]]));

    expect($policy->runwayPartitions)->toBe(3)
        ->and(array_map(static fn (PartitionedTable $table): string => $table->name, $policy->tables))->toBe(['receipts'])
        ->and(array_map(
            static fn (SequencePartitionedTable $table): string => sprintf(
                '%s %d %s %s',
                $table->name,
                $table->width,
                $table->sequence,
                $table->retention instanceof SequenceRetention ? $table->retention->days.' days by '.$table->retention->column : 'keep',
            ),
            $policy->sequenceTables,
        ))->toBe([
            'events 1000000 events_event_id_seq 30 days by occurred_at',
            'revision_payloads_published 10000000 revisions_revision_id_seq keep',
        ]);
});

it('defaults to two empty partitions ahead of a sequence and lists no table on a sequence', function (): void {
    $policy = PartitionConfig::read(config());

    expect($policy->runwayPartitions)->toBe(PartitionPolicy::DEFAULT_RUNWAY_PARTITIONS)
        ->and($policy->runwayPartitions)->toBe(2)
        ->and($policy->sequenceTables)->toBe([]);
});

it('names the setting of a table with a bigint key that is wrong', function (array $settings, string $message): void {
    expect(static fn (): PartitionPolicy => PartitionConfig::read(new Repository(['cbox-cms' => ['database' => [
        'owner_connection' => 'o',
        'partitions' => ['tables' => ['events' => $settings]],
    ]]])))->toThrow(InvalidPartitionPolicy::class, $message);
})->with([
    'an interval' => [['key' => 'bigint', 'interval' => 'day', 'width' => 100, 'sequence' => 's'], '[cbox-cms.database.partitions.tables.events.interval] is "day". Use no interval for the key "bigint"'],
    'no width' => [['key' => 'bigint', 'sequence' => 's'], '[cbox-cms.database.partitions.tables.events.width] is "null"'],
    'width as text' => [['key' => 'bigint', 'width' => '100', 'sequence' => 's'], '[cbox-cms.database.partitions.tables.events.width] is "100"'],
    'no sequence' => [['key' => 'bigint', 'width' => 100], '[cbox-cms.database.partitions.tables.events.sequence] is "null"'],
    'retention as text' => [['key' => 'bigint', 'width' => 100, 'sequence' => 's', 'retention_days' => '30', 'retention_column' => 'at'], '[cbox-cms.database.partitions.tables.events.retention_days] is "30"'],
    'retention without a column' => [['key' => 'bigint', 'width' => 100, 'sequence' => 's', 'retention_days' => 30], '[cbox-cms.database.partitions.tables.events.retention_column] is "null". Use the timestamptz column'],
    'a column without retention' => [['key' => 'bigint', 'width' => 100, 'sequence' => 's', 'retention_column' => 'at'], '[cbox-cms.database.partitions.tables.events.retention_column] is "at". Use null'],
    'a width under 1' => [['key' => 'bigint', 'width' => 0, 'sequence' => 's'], 'The partition width of table "events" is 0 ids'],
    'an unknown key' => [['key' => 'int', 'width' => 100, 'sequence' => 's'], '[cbox-cms.database.partitions.tables.events.key] is "int". Use "uuid7", "timestamp" or "bigint"'],
]);

it('refuses the runway in partitions as text', function (): void {
    expect(static fn (): PartitionPolicy => PartitionConfig::read(new Repository(['cbox-cms' => ['database' => [
        'owner_connection' => 'o',
        'partitions' => ['runway_partitions' => '2'],
    ]]])))->toThrow(InvalidPartitionPolicy::class, '[cbox-cms.database.partitions.runway_partitions] is "2"');
});

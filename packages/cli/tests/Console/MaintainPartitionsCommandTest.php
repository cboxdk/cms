<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Tests\Partitions\Fakes\FakePartitionMaintenance;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

/*
 * cms:partitions:maintain with the action on the fake manager and the FakeClock: what it prints,
 * what it logs and how it exits for each outcome. MaintainPartitionsCommandTest in the Postgres
 * suite runs it on real partitions.
 */

/**
 * Binds the action to a fake manager of one daily table at 2026-05-01 10:00 UTC, and a logger.
 *
 * @return array{FakePartitionMaintenance, RecordingLogger}
 */
function partitionsCommandWith(?int $retentionDays = null, string $ownerConnection = 'pgsql_owner'): array
{
    $partitions = new FakePartitionMaintenance(new PartitionPolicy(
        $ownerConnection,
        [new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, $retentionDays)],
        runwayDays: 1,
        attempts: 3,
    ));
    $logger = new RecordingLogger;

    app()->instance(MaintainPartitions::class, new MaintainPartitions($partitions, new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z'))));
    app()->instance(LoggerInterface::class, $logger);

    return [$partitions, $logger];
}

/**
 * @param  array<array-key, mixed>  $options
 * @return array{int, list<string>}
 */
function runPartitionsCommand(array $options = []): array
{
    $status = Artisan::call(CoreServiceProvider::PARTITIONS_COMMAND, $options);
    $lines = array_values(array_filter(array_map(rtrim(...), explode("\n", Artisan::output())), static fn (string $line): bool => $line !== ''));

    return [$status, $lines];
}

it('creates the runway, prints each change and each table\'s runway, and logs them', function (): void {
    [, $logger] = partitionsCommandWith();

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(0)
        ->and($lines)->toBe([
            'created events.events_p20260501',
            'created events.events_p20260502',
            'runway events until 2026-05-03T00:00:00Z',
            'Partitions maintained as role cms_owner: 2 changes.',
        ])
        ->and($logger->records)->toBe([['info', 'Partition maintenance ran.', [
            'role' => 'cms_owner',
            'changes' => ['created events_p20260501', 'created events_p20260502'],
            'runways' => ['events 2026-05-03T00:00:00Z'],
        ]]]);
});

it('prints and logs the partitions it detaches and drops past retention', function (): void {
    [$partitions] = partitionsCommandWith(retentionDays: 1);
    runPartitionsCommand(['--from' => '2026-04-28', '--to' => '2026-04-28']);
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(0)
        ->and($lines)->toBe([
            'created events.events_p20260501',
            'created events.events_p20260502',
            'detached events.events_p20260428',
            'dropped events.events_p20260428',
            'runway events until 2026-05-03T00:00:00Z',
            'Partitions maintained as role cms_owner: 4 changes.',
        ])
        ->and($logger->records)->toBe([['info', 'Partition maintenance ran.', [
            'role' => 'cms_owner',
            'changes' => ['created events_p20260501', 'created events_p20260502', 'detached events_p20260428', 'dropped events_p20260428'],
            'runways' => ['events 2026-05-03T00:00:00Z'],
        ]]])
        ->and($partitions->partitions('events'))->toBe(['events_p20260501', 'events_p20260502']);
});

it('only covers the range of --from and --to, and prints a runway of none when no partition holds now', function (): void {
    [$partitions, $logger] = partitionsCommandWith(retentionDays: 1);

    [$status, $lines] = runPartitionsCommand(['--from' => '2024-02-28T23:00:00Z', '--to' => '2024-03-01']);

    expect($status)->toBe(0)
        ->and($lines)->toBe([
            'created events.events_p20240228',
            'created events.events_p20240229',
            'created events.events_p20240301',
            'runway events until none',
            'Partitions maintained as role cms_owner: 3 changes.',
        ])
        ->and($logger->records)->toBe([['info', 'Partition maintenance ran.', [
            'role' => 'cms_owner',
            'changes' => ['created events_p20240228', 'created events_p20240229', 'created events_p20240301'],
            'runways' => ['events none'],
        ]]])
        ->and($partitions->partitions('events'))->toBe(['events_p20240228', 'events_p20240229', 'events_p20240301']);
});

it('exits 2 with the reason for invalid options or a range it will not cover, and changes nothing', function (array $options, string $message): void {
    [$partitions, $logger] = partitionsCommandWith();

    [$status, $lines] = runPartitionsCommand($options);

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_INVALID)
        ->and(implode("\n", $lines))->toContain($message)
        ->and($partitions->partitions('events'))->toBe([])
        ->and($logger->records)->toBe([]);
})->with([
    'only --from' => [['--from' => '2026-05-01'], 'Give both --from and --to, or neither.'],
    '--to before --from' => [['--from' => '2026-05-02', '--to' => '2026-05-01'], '2026-05-01'],
    'more partitions than one call makes' => [['--from' => '2020-01-01', '--to' => '2026-01-01'], 'events'],
]);

it('exits 75 when a lock stays busy, prints why and logs the step as a warning', function (): void {
    [$partitions, $logger] = partitionsCommandWith();
    $partitions->holdRunLock();

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_LOCK_TIMEOUT)
        ->and(implode("\n", $lines))->toContain('Gave up on step "lock"')
        ->and($logger->records)->toBe([['warning', 'Partition maintenance gave up on a lock.', [
            'code' => 'partition_lock_timeout',
            'step' => 'lock',
            'table' => null,
            'partition' => null,
            'attempts' => 3,
            'cause' => null,
        ]]]);
});

it('reports the run, then logs the table and partition of a lock that stays busy on one table', function (): void {
    [$partitions, $logger] = partitionsCommandWith();
    $partitions->lockTable('events');

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_LOCK_TIMEOUT)
        ->and(array_slice($lines, 0, 2))->toBe([
            'runway events until none',
            'Partitions maintained as role cms_owner: 0 changes.',
        ])
        ->and(implode("\n", array_slice($lines, 2)))->toStartWith('[partition_lock_timeout] Gave up on step "create" for partition "events_p20260501" of table "events"')
        ->and($logger->records)->toBe([
            ['info', 'Partition maintenance ran.', ['role' => 'cms_owner', 'changes' => [], 'runways' => ['events none']]],
            ['warning', 'Partition maintenance gave up on a lock.', [
                'code' => 'partition_lock_timeout',
                'step' => 'create',
                'table' => 'events',
                'partition' => 'events_p20260501',
                'attempts' => 3,
                'cause' => null,
            ]],
        ]);
});

it('exits 78 when the policy names the application\'s connection, and changes nothing', function (): void {
    [$partitions, $logger] = partitionsCommandWith(ownerConnection: 'pgsql');

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_NOT_OWNER)
        ->and(implode("\n", $lines))->toContain('which is the application\'s default connection')
        ->and($partitions->partitions('events'))->toBe([])
        ->and($logger->records)->toBe([]);
});

it('reports the run, then prints and logs each table it could not manage, and exits 78 even when a lock was busy too', function (): void {
    $partitions = new FakePartitionMaintenance(new PartitionPolicy(
        'pgsql_owner',
        [
            new PartitionedTable('audit', PartitionKey::Uuid7, PartitionInterval::Day, null),
            new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, null),
            new PartitionedTable('metrics', PartitionKey::Timestamp, PartitionInterval::Day, null),
        ],
        runwayDays: 1,
        attempts: 3,
    ));
    $logger = new RecordingLogger;
    app()->instance(MaintainPartitions::class, new MaintainPartitions($partitions, new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z'))));
    app()->instance(LoggerInterface::class, $logger);
    $partitions->dropTable('audit');
    $partitions->lockTable('metrics');

    [$status, $lines] = runPartitionsCommand();

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_UNMANAGEABLE)
        ->and(array_slice($lines, 0, 5))->toBe([
            'created events.events_p20260501',
            'created events.events_p20260502',
            'runway events until 2026-05-03T00:00:00Z',
            'runway metrics until none',
            'Partitions maintained as role cms_owner: 2 changes.',
        ])
        ->and(implode("\n", array_slice($lines, 5)))->toStartWith('[partition_lock_timeout] Gave up on step "create" for partition "metrics_p20260501" of table "metrics"')
        ->and(implode("\n", array_slice($lines, 5)))->toEndWith('[partition_table_unmanageable] The table "audit" is listed in [cms.database.partitions.tables] but does not exist in the search path of the connection [pgsql_owner]. Run the migrations first.')
        ->and($partitions->partitions('events'))->toBe(['events_p20260501', 'events_p20260502'])
        ->and($logger->records[2])->toBe(['error', 'Partition maintenance could not manage a table.', [
            'code' => 'partition_table_unmanageable',
            'table' => 'audit',
            'partition' => null,
            'cause' => null,
        ]])
        ->and(array_column($logger->records, 0))->toBe(['info', 'warning', 'error']);

    $partitions->unlockTable('metrics');

    expect(runPartitionsCommand()[0])->toBe(MaintainPartitionsCommand::EXIT_UNMANAGEABLE);
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Tests\Postgres\PartitionScratch;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Contracts\Console\Kernel;

/*
 * `cms:partitions:maintain` against real Postgres: it maintains at the Clock's time, creates a
 * range with --from and --to, and turns the manager's typed errors into exit codes.
 */

beforeEach(function (): void {
    PartitionScratch::create();
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 7])], ['runway_days' => 2]);
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    PartitionScratch::drop();
});

/**
 * Runs the command and returns its exit code and output lines.
 *
 * @param  array<array-key, mixed>  $options
 * @return array{int, list<string>}
 */
function maintainCommand(array $options = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:partitions:maintain', $options);

    return [$status, array_values(array_filter(array_map(trim(...), explode("\n", $artisan->output())), static fn (string $line): bool => $line !== ''))];
}

it('maintains partitions at the Clock\'s time and prints what it did', function (): void {
    PartitionScratch::clockAt('2026-01-01T10:00:00Z');

    expect(maintainCommand(['--from' => '2025-12-01', '--to' => '2025-12-01'])[0])->toBe(0)
        ->and(maintainCommand())->toBe([0, [
            'created partition_scratch.partition_scratch_p20260101',
            'created partition_scratch.partition_scratch_p20260102',
            'created partition_scratch.partition_scratch_p20260103',
            'detached partition_scratch.partition_scratch_p20251201',
            'dropped partition_scratch.partition_scratch_p20251201',
            'runway partition_scratch until 2026-01-04T00:00:00Z',
            'Partitions maintained as role cms_owner: 5 changes.',
        ]]);
});

it('creates only the partitions of the range given with --from and --to, and removes nothing', function (): void {
    PartitionScratch::clockAt('2031-01-01T00:00:00Z');

    expect(maintainCommand(['--from' => '2024-02-28T23:00:00+00:00', '--to' => '2024-03-01T00:30:00.500000+02:00']))->toBe([0, [
        'created partition_scratch.partition_scratch_p20240228',
        'created partition_scratch.partition_scratch_p20240229',
        'runway partition_scratch until 2024-03-01T00:00:00Z',
        'Partitions maintained as role cms_owner: 2 changes.',
    ]])
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe(['partition_scratch_p20240228', 'partition_scratch_p20240229']);
});

it('exits 2 on invalid options, without touching the database', function (array $options, string $message): void {
    [$status, $output] = maintainCommand($options);

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_INVALID)
        ->and(implode("\n", $output))->toContain($message)
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe([]);
})->with([
    'only --from' => [['--from' => '2026-01-01'], 'Give both --from and --to, or neither.'],
    'only --to' => [['--to' => '2026-01-01'], 'Give both --from and --to, or neither.'],
    'not a date' => [['--from' => 'yesterday', '--to' => '2026-01-01'], 'The option --from is "yesterday".'],
    'impossible date' => [['--from' => '2026-02-30', '--to' => '2026-03-01'], 'The option --from is "2026-02-30".'],
    'backwards' => [['--from' => '2026-01-02', '--to' => '2026-01-01'], 'The range ends at 2026-01-01T00:00:00+00:00, before it starts'],
]);

it('exits 78 when the owner connection is the app connection', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['owner_connection' => 'pgsql']);

    [$status, $output] = maintainCommand();

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_NOT_OWNER)
        ->and(implode("\n", $output))->toContain('['.OwnerConnectionRequired::CODE.']');
});

it('exits 75 when a lock stays busy on every attempt', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['attempts' => 1]);
    [$other] = app(IndependentConnections::class)->open(1, 'pgsql_owner');
    $other->select('select pg_advisory_lock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);

    [$status, $output] = maintainCommand();
    $other->select('select pg_advisory_unlock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);

    expect($status)->toBe(MaintainPartitionsCommand::EXIT_LOCK_TIMEOUT)
        ->and(implode("\n", $output))->toContain('['.LockTimeout::CODE.']');
});

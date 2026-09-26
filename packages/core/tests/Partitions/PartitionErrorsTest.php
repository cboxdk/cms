<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Illuminate\Database\QueryException;
use PDOException;

function queryError(string $sqlState, string $message): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$sqlState.']: '.$message);
    $pdo->errorInfo = [$sqlState, 7, 'ERROR:  '.$message];

    return new QueryException('pgsql', 'insert into receipts (id) values (?)', ['x'], $pdo);
}

it('maps the no-partition error to PartitionMissing with its code and the table', function (): void {
    $error = queryError('23514', 'no partition of relation "receipts" found for row
DETAIL:  Partition key of the failing row contains (id) = (019c0000-0000-7000-8000-000000000000).');

    $mapped = MissingPartitionMapper::map($error);

    expect($mapped)->toBeInstanceOf(PartitionMissing::class);
    assert($mapped instanceof PartitionMissing);

    expect($mapped->table)->toBe('receipts')
        ->and($mapped->getPrevious())->toBe($error)
        ->and($mapped->getMessage())->toBe('[partition_missing] No partition of table "receipts" covers the row. Partitioned tables have no DEFAULT partition, so a write outside the partitions that exist fails. Run `php artisan cms:partitions:maintain` and check that the scheduler runs it; the command creates partitions ahead of the clock.');
});

it('leaves other errors as they are, a CHECK violation with the same SQLSTATE included', function (string $sqlState, string $message): void {
    $error = queryError($sqlState, $message);

    expect(MissingPartitionMapper::map($error))->toBe($error)
        ->and(MissingPartitionMapper::partitionMissing($error))->toBeNull();
})->with([
    'check constraint' => ['23514', 'new row for relation "receipts" violates check constraint "receipts_note_check"'],
    'same text, other state' => ['23505', 'no partition of relation "receipts" found for row'],
    'lock timeout' => ['55P03', 'canceling statement due to lock timeout'],
]);

it('writes its codes into the messages of the partition errors', function (): void {
    expect(LockTimeout::gaveUp(DdlStep::Detach, 'receipts', 'receipts_p20260101', 3, '2s')->getMessage())
        ->toBe('[partition_lock_timeout] Gave up on step "detach" for partition "receipts_p20260101" of table "receipts" after 3 attempts: each time another session held a conflicting lock for longer than lock_timeout 2s. The step changed nothing, and the next run tries again. To see what holds the lock, query pg_locks joined with pg_stat_activity.')
        ->and(LockTimeout::gaveUp(DdlStep::Lock, null, null, 1, '2s')->getMessage())
        ->toContain('Gave up on step "lock" for the partition maintenance lock after 1 attempt:')
        ->and(OwnerConnectionRequired::appConnection('pgsql')->getMessage())
        ->toStartWith('[partition_owner_required] Partition maintenance is set to run on the connection [pgsql]');
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Core\Database\Infrastructure\TableGrant;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The idempotency table as the migration and the partition manager leave it (PRD 4, 4.1, 4.2,
 * 6.1): RANGE on created_at per day, the lock key index on every partition, no unique key, owned
 * by the owner role, SELECT and INSERT only for the app role on every level, and old partitions
 * dropped by the partition manager.
 */

beforeEach(function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-31T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:00Z'));
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
});

it('partitions idempotency_keys by RANGE on created_at, one partition per day, with no DEFAULT partition', function (): void {
    $owner = ReceiptTables::owner();

    $leaves = ReceiptTables::texts(
        $owner,
        "select c.relname::text || ' ' || c.relkind::text as value from pg_partition_tree('idempotency_keys') t join pg_class c on c.oid = t.relid where t.level = 1 order by 1",
    );

    expect($owner->scalar("select relkind::text from pg_class where oid = 'idempotency_keys'::regclass"))->toBe('p')
        ->and($owner->scalar("select pg_get_partkeydef('idempotency_keys'::regclass)"))->toBe('RANGE (created_at)')
        ->and($owner->scalar("select partdefid::int from pg_partitioned_table where partrelid = 'idempotency_keys'::regclass"))->toBe(0)
        ->and($leaves)->toContain('idempotency_keys_p20260131 r', 'idempotency_keys_p20260201 r');

    foreach ($leaves as $leaf) {
        expect($leaf)->toMatch('/^idempotency_keys_p\d{8} r$/');
    }

    // The bound is printed in the session's time zone.
    $owner->statement("set timezone = 'UTC'");
    $bound = $owner->scalar("select pg_get_expr(relpartbound, oid) from pg_class where relname = 'idempotency_keys_p20260131'");
    $owner->statement('reset timezone');

    expect($bound)->toBe("FOR VALUES FROM ('2026-01-31 00:00:00+00') TO ('2026-02-01 00:00:00+00')");
});

it('indexes the lock key on every partition and has no unique key', function (): void {
    $owner = ReceiptTables::owner();
    $relations = ReceiptTables::texts($owner, "select relid::regclass::text as value from pg_partition_tree('idempotency_keys') order by level, 1");

    foreach ($relations as $relation) {
        $indexes = ReceiptTables::texts(
            $owner,
            "select regexp_replace(pg_get_indexdef(indexrelid), ' ON (ONLY )?[a-z0-9_.]+ ', ' ON t ') as value from pg_index where indrelid = ?::regclass",
            [$relation],
        );

        expect($indexes)->toHaveCount(1, $relation)
            ->and($indexes[0])->toMatch('/^CREATE INDEX [a-z0-9_]+ ON t USING btree \(lock_key\)$/', $relation);
    }

    expect($owner->scalar("select count(*) from pg_constraint where contype in ('p', 'u', 'f') and conrelid in (select relid from pg_partition_tree('idempotency_keys'))"))->toBe(0);
});

it('refuses a record that expires more than 7 days after it was created', function (): void {
    try {
        ReceiptTables::owner()->table(PostgresIdempotencyStore::TABLE)->insert([
            'lock_key' => 1,
            'principal_kind' => 'actor',
            'principal' => 'user:7',
            'command_type' => 'entry.release',
            'idempotency_key' => 'too-long',
            'content_hash' => IdempotencyTables::hash()->value,
            'changeset_id' => IdempotencyTables::changeset('2026-01-31T00:00:00Z')->toString(),
            'expires_at' => '2026-02-07T00:00:00.001Z',
            'created_at' => '2026-01-31T00:00:00Z',
        ]);
        throw new AssertionFailedError('A record outside the lookup window was written.');
    } catch (QueryException $exception) {
        expect(SqlError::of($exception)->sqlState)->toBe('23514')
            ->and($exception->getMessage())->toContain('idempotency_keys_window');
    }
});

it('grants the app role only SELECT and INSERT on idempotency_keys, on every level', function (): void {
    $owner = ReceiptTables::owner();
    $relations = ReceiptTables::texts($owner, "select relid::regclass::text as value from pg_partition_tree('idempotency_keys') order by level, 1");

    expect(count($relations))->toBeGreaterThanOrEqual(3);

    foreach ($relations as $relation) {
        $grants = array_map(
            static fn (TableGrant $grant): string => $grant->role.' '.$grant->privilege->value.($grant->grantable ? ' grantable' : ''),
            new TablePrivileges($owner)->grants($relation),
        );

        expect($grants)->toBe(['cms_app INSERT', 'cms_app SELECT'], $relation);
    }
});

it('gives the app role no DELETE, UPDATE or TRUNCATE of a record, not even on a partition directly', function (string $sql): void {
    try {
        DB::connection()->statement($sql);
        throw new AssertionFailedError(sprintf('The app role ran: %s', $sql));
    } catch (QueryException $exception) {
        expect(SqlError::of($exception)->sqlState)->toBe('42501');
    }
})->with([
    'delete' => ['delete from idempotency_keys'],
    'update' => ['update idempotency_keys set changeset_id = changeset_id'],
    'truncate' => ['truncate idempotency_keys'],
    'delete from a partition' => ['delete from idempotency_keys_p20260131'],
    'update a partition' => ["update idempotency_keys_p20260131 set principal = 'x'"],
]);

it('leaves the table and its partitions to the owner role', function (): void {
    $owners = ReceiptTables::texts(
        ReceiptTables::owner(),
        "select distinct tableowner::text as value from pg_tables where schemaname = 'cms' and tablename like 'idempotency_keys%'",
    );

    expect($owners)->toBe(['cms_owner']);
});

it('drops an idempotency partition a week after its day ends, when every record in it has expired', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-09T00:00:00Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T23:59:59.999Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();

    $session->begin();
    $fresh = $session->idempotency()->claim(IdempotencyTables::scope(), IdempotencyTables::key(), IdempotencyTables::hash(), WaitBudget::none());
    $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), IdempotencyTables::changeset('2026-01-01T23:59:59.999Z'));
    $session->commit();

    // 2026-01-01 ended at 2026-01-02; its last record expired at 2026-01-08T23:59:59.999.
    app()->instance(Clock::class, $clock);
    $clock->set(new DateTimeImmutable('2026-01-08T23:59:59.999Z'));
    $early = app(MaintainPartitions::class)->maintain();

    expect(array_filter($early->partitions(PartitionChangeKind::Dropped), static fn (string $name): bool => str_starts_with($name, 'idempotency_keys')))->toBe([])
        ->and(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(1);

    $clock->set(new DateTimeImmutable('2026-01-09T00:00:00Z'));
    $report = app(MaintainPartitions::class)->maintain();
    $dropped = array_values(array_filter($report->partitions(PartitionChangeKind::Dropped), static fn (string $name): bool => str_starts_with($name, 'idempotency_keys')));

    expect($dropped)->toBe(['idempotency_keys_p20260101'])
        ->and(ReceiptTables::texts(ReceiptTables::owner(), "select relname::text as value from pg_class where relname in ('idempotency_keys_p20260101', 'idempotency_keys_p20260102') order by 1"))
        ->toBe(['idempotency_keys_p20260102'])
        ->and(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(0);
});

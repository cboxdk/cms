<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\ColumnGrant;
use Cbox\Cms\Core\Database\Infrastructure\TableGrant;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The receipt tables as the migration and the partition manager leave them (PRD 4, 4.1, 4.2,
 * 8.4): LIST by retention class, then RANGE on changeset_id per day for Standard and per month for
 * Evidence, owned by the owner role, with only the DML the store needs for the app role on every
 * level: UPDATE on projection rows only of the two columns markProjection() writes, and never of
 * an acknowledged row.
 */

beforeEach(function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-31T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:00Z'));
});

it('partitions receipts and receipt_projections by LIST on retention_class and then by RANGE on changeset_id', function (string $table): void {
    $owner = ReceiptTables::owner();

    $levels = ReceiptTables::texts(
        $owner,
        <<<'SQL'
            select t.level || ' ' || c.relname::text || ' ' || c.relkind::text || ' ' || coalesce(p.partstrat::text, '-') as value
            from pg_partition_tree(?::regclass) t
            join pg_class c on c.oid = t.relid
            left join pg_partitioned_table p on p.partrelid = t.relid
            order by t.level, c.relname
            SQL,
        [$table],
    );

    // Partitions from other tests in this process stay until the schema is rebuilt, so the leaf
    // level is checked for the ones this test covered and for its shape.
    $branches = array_values(array_filter($levels, static fn (string $level): bool => ! str_starts_with($level, '2 ')));
    $leaves = array_values(array_filter($levels, static fn (string $level): bool => str_starts_with($level, '2 ')));

    expect($owner->scalar('select relkind::text from pg_class where oid = ?::regclass', [$table]))->toBe('p')
        ->and($branches)->toBe([
            "0 {$table} p l",
            "1 {$table}_evidence p r",
            "1 {$table}_standard p r",
        ])
        ->and($leaves)->toContain(
            "2 {$table}_evidence_p202601 r -",
            "2 {$table}_evidence_p202602 r -",
            "2 {$table}_standard_p20260131 r -",
            "2 {$table}_standard_p20260201 r -",
        );

    foreach ($leaves as $leaf) {
        expect($leaf)->toMatch("/^2 {$table}_(standard_p\\d{8}|evidence_p\\d{6}) r -$/");
    }

    expect($owner->scalar('select pg_get_partkeydef(?::regclass)', [$table]))->toBe('LIST (retention_class)')
        ->and($owner->scalar('select pg_get_partkeydef(?::regclass)', [$table.'_standard']))->toBe('RANGE (changeset_id)')
        ->and($owner->scalar('select pg_get_expr(relpartbound, oid) from pg_class where oid = ?::regclass', [$table.'_evidence']))->toBe("FOR VALUES IN ('evidence')")
        ->and($owner->scalar('select partdefid::int from pg_partitioned_table where partrelid = ?::regclass', [$table]))->toBe(0);
})->with(['receipts', 'receipt_projections']);

it('keys a receipt by changeset and retention class, and a projection row by changeset, class and projection', function (): void {
    $keys = ReceiptTables::texts(
        ReceiptTables::owner(),
        <<<'SQL'
            select conrelid::regclass::text || ': ' || pg_get_constraintdef(oid) as value
            from pg_constraint
            where contype = 'p' and conrelid in ('receipts'::regclass, 'receipt_projections'::regclass)
            order by 1
            SQL,
    );

    expect($keys)->toBe([
        'receipt_projections: PRIMARY KEY (changeset_id, retention_class, projection)',
        'receipts: PRIMARY KEY (changeset_id, retention_class)',
    ]);
});

it('keeps only the facts of the changeset in the receipt tables, never a call\'s outcome or wait level', function (): void {
    $columns = static fn (string $table): array => ReceiptTables::texts(
        ReceiptTables::owner(),
        'select column_name::text as value from information_schema.columns where table_schema = current_schema() and table_name = ? order by ordinal_position',
        [$table],
    );

    // The store writes in the command transaction (PRD 6.2 phase 7), before any wait level past
    // commit is reached, so an outcome or a wait level stored there would be a guess.
    // The commit position is a fact of the changeset: the xid8 of the command transaction, which
    // no update changes (PRD 8.4).
    expect($columns(PostgresReceiptStore::RECEIPTS))->toBe(['changeset_id', 'retention_class', 'position'])
        ->and(ReceiptTables::texts(ReceiptTables::owner(), "select data_type || ' ' || is_nullable || ' ' || coalesce(column_default, 'none') as value from information_schema.columns where table_schema = current_schema() and table_name = 'receipts' and column_name = 'position'"))->toBe(['xid8 NO none'])
        ->and($columns(PostgresReceiptStore::PROJECTIONS))->toBe(['changeset_id', 'retention_class', 'projection', 'state', 'acknowledged_at'])
        ->and(ReceiptTables::owner()->scalar("select count(*) from pg_constraint where contype = 'c' and conrelid = 'receipts'::regclass"))->toBe(0);
});

it('has no foreign key between the receipt tables, which are written and dropped together', function (): void {
    $foreignKeys = ReceiptTables::owner()->scalar(
        "select count(*) from pg_constraint where contype = 'f' and conrelid in (select relid from pg_partition_tree('receipts') union all select relid from pg_partition_tree('receipt_projections'))",
    );

    expect($foreignKeys)->toBe(0);
});

it('grants the app role only SELECT and INSERT on receipts, and SELECT, INSERT and UPDATE of state and acknowledged_at on projection rows, on every level', function (string $table, string $privileges, string $updatable): void {
    $owner = ReceiptTables::owner();
    $expected = explode(',', $privileges);
    $expectedColumns = $updatable === '' ? [] : explode(',', $updatable);
    $relations = ReceiptTables::texts($owner, 'select relid::regclass::text as value from pg_partition_tree(?::regclass) order by level, relid::regclass::text', [$table]);
    $columns = ReceiptTables::texts($owner, 'select attname::text as value from pg_attribute where attrelid = ?::regclass and attnum > 0 and not attisdropped order by attnum', [$table]);

    expect(count($relations))->toBeGreaterThanOrEqual(7);

    foreach ($relations as $relation) {
        $grants = array_map(
            static fn (TableGrant $grant): string => $grant->role.' '.$grant->privilege->value.($grant->grantable ? ' grantable' : ''),
            new TablePrivileges($owner)->grants($relation),
        );
        $columnGrants = array_map(
            static fn (ColumnGrant $grant): string => $grant->role.' '.$grant->privilege->value.' '.$grant->column.($grant->grantable ? ' grantable' : ''),
            new TablePrivileges($owner)->columnGrants($relation),
        );

        expect($grants)->toBe(array_map(static fn (string $privilege): string => 'cms_app '.$privilege, $expected), $relation)
            ->and($columnGrants)->toBe(array_map(static fn (string $column): string => 'cms_app UPDATE '.$column, $expectedColumns), $relation);

        foreach (TablePrivilege::cases() as $privilege) {
            expect($owner->scalar("select has_table_privilege('cms_app', ?::regclass, ?)", [$relation, $privilege->value]))
                ->toBe(in_array($privilege->value, $expected, true), "{$privilege->value} on {$relation}");
        }

        foreach ($columns as $column) {
            expect($owner->scalar("select has_column_privilege('cms_app', ?::regclass, ?, 'UPDATE')", [$relation, $column]))
                ->toBe(in_array($column, $expectedColumns, true), "UPDATE of {$column} on {$relation}");
        }
    }
})->with([
    'receipts' => ['receipts', 'INSERT,SELECT', ''],
    'receipt_projections' => ['receipt_projections', 'INSERT,SELECT', 'acknowledged_at,state'],
]);

it('refuses the app role an update of any projection column but state and acknowledged_at, through the table and through a partition', function (string $assignment): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-02T00:00:00Z'));
    $store = new PostgresReceiptStore(app('db'), new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z')));
    [$evidence] = ReceiptTables::commit(DB::connection(), $store, ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::Evidence));

    foreach (['receipt_projections', 'receipt_projections_evidence', 'receipt_projections_evidence_p202601'] as $relation) {
        $refused = null;

        try {
            DB::connection()->update(sprintf('update %s set %s where changeset_id = ?', $relation, $assignment), [$evidence->changesetId->toString()]);
        } catch (QueryException $exception) {
            $refused = $exception;
        }

        expect($refused)->toBeInstanceOf(QueryException::class, "{$assignment} on {$relation}")
            ->and($refused?->getCode())->toBe('42501', "{$assignment} on {$relation}");
    }

    // The Evidence rows are where they were, and the store still acknowledges them.
    expect(ReceiptTables::texts(ReceiptTables::owner(), 'select retention_class || \' \' || projection || \' \' || state as value from receipt_projections where changeset_id = ? order by projection', [$evidence->changesetId->toString()]))
        ->toBe(['evidence edge pending', 'evidence fragments pending', 'evidence search pending'])
        ->and($store->markProjection($evidence->changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), new DateTimeImmutable('2026-01-01T00:00:02Z'))))->toBeTrue()
        ->and($store->find($evidence->changesetId)?->projections[0]->state)->toBe(ProjectionState::Acknowledged);
})->with([
    'the retention class' => ["retention_class = 'standard'"],
    'the changeset' => ["changeset_id = '00000000-0000-7000-8000-000000000000'"],
    'the projection' => ["projection = 'other'"],
    'a key column next to state' => ["state = 'acknowledged', acknowledged_at = now(), projection = 'other'"],
]);

it('refuses every update of an acknowledged projection row, for the app role and the owner, so an acknowledgement is final', function (string $connection, string $assignment): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-02T00:00:00Z'));
    $store = new PostgresReceiptStore(app('db'), new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z')));
    [$receipt] = ReceiptTables::commit(DB::connection(), $store, ReceiptTables::receipt('2026-01-01T00:00:00Z'));
    $store->markProjection($receipt->changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), new DateTimeImmutable('2026-01-01T00:00:02Z')));

    $refused = null;

    try {
        DB::connection($connection)->update(sprintf("update receipt_projections set %s where changeset_id = ? and projection = 'edge'", $assignment), [$receipt->changesetId->toString()]);
    } catch (QueryException $exception) {
        $refused = $exception;
    }

    expect($refused)->toBeInstanceOf(QueryException::class)
        ->and($refused?->getCode())->toBe('23000')
        ->and($refused?->getMessage())->toContain('The projection edge of changeset '.$receipt->changesetId->toString().' is acknowledged, and an acknowledgement is final.')
        ->and(ReceiptTables::texts(ReceiptTables::owner(), "select state || ' ' || to_char(acknowledged_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') as value from receipt_projections where changeset_id = ? and projection = 'edge'", [$receipt->changesetId->toString()]))
        ->toBe(['acknowledged 2026-01-01T00:00:02'])
        // A pending row of the same receipt still changes.
        ->and(DB::connection($connection)->update("update receipt_projections set state = 'acknowledged', acknowledged_at = now() where changeset_id = ? and projection = 'search'", [$receipt->changesetId->toString()]))->toBe(1);
})->with([
    'the app role back to pending' => ['pgsql', 'state = \'pending\', acknowledged_at = null'],
    'the app role to another time' => ['pgsql', 'acknowledged_at = acknowledged_at + interval \'1 hour\''],
    'the app role to the same values' => ['pgsql', 'state = state'],
    'the owner back to pending' => ['pgsql_owner', 'state = \'pending\', acknowledged_at = null'],
    'the owner to another class' => ['pgsql_owner', "retention_class = 'evidence'"],
]);

it('leaves every receipt table and partition to the owner role, so the app role owns no table', function (): void {
    $owners = ReceiptTables::texts(
        ReceiptTables::owner(),
        <<<'SQL'
            select distinct tableowner::text as value from pg_tables
            where schemaname = 'cms' and (tablename like 'receipts%' or tablename like 'receipt_projections%')
            SQL,
    );

    expect($owners)->toBe(['cms_owner'])
        ->and(DB::connection()->scalar("select count(*) from pg_tables where tableowner = 'cms_app'"))->toBe(0);
});

it('drops the Standard receipt partitions a week after their day ends and keeps every Evidence partition', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-02T00:00:00Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $store = new PostgresReceiptStore(app('db'), $clock);
    [, $evidence] = ReceiptTables::commit(DB::connection(), $store, ReceiptTables::receipt('2026-01-01T00:00:00Z', sequence: 1), ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::Evidence));

    // 2026-01-01 ended at 2026-01-02; seven days later its Standard partitions go.
    app()->instance(Clock::class, $clock);
    $clock->set(new DateTimeImmutable('2026-01-09T00:00:00Z'));
    $report = app(MaintainPartitions::class)->maintain();

    expect($report->partitions(PartitionChangeKind::Dropped))->toBe(['receipts_standard_p20260101', 'receipt_projections_standard_p20260101', 'idempotency_keys_p20260101'])
        ->and(ReceiptTables::texts(ReceiptTables::owner(), "select relname::text as value from pg_class where relname in ('receipts_standard_p20260101', 'receipts_standard_p20260102', 'receipts_evidence_p202601') order by 1"))
        ->toBe(['receipts_evidence_p202601', 'receipts_standard_p20260102'])
        ->and($store->find($evidence->changesetId))->toEqual($evidence);

    $clock->set(new DateTimeImmutable('2036-01-09T00:00:00Z'));
    expect($store->find($evidence->changesetId))->toEqual($evidence);
});

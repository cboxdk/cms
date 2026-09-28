<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Consistency\UnsupportedIsolation;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The Postgres receipt store beyond the shared suite (GUARDRAILS 4.1 and 9, PRD 4, 4.1, 8.4):
 * one transaction with the caller's own writes, no effect outside Postgres, one row per
 * projection under concurrent workers, partition pruning, missing partitions and the app role's
 * privileges. The shared cases run in PostgresReceiptStoreContractTest.
 */

beforeEach(function (): void {
    ReceiptTables::createCallerTable();
});

afterEach(function (): void {
    app(ChildProcesses::class)->stopAll();
    app(IndependentConnections::class)->closeAll();
    ReceiptTables::dropCallerTable();
});

/**
 * Waits until a backend of the app role waits for a lock, for at most five seconds: the child has
 * reached the row the test holds. It asks as the app role, because pg_stat_activity shows the wait
 * of a backend only to its own role and to superusers.
 */
function waitForReceiptLockWaiter(): void
{
    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $waiting = DB::connection()->scalar(
            "select count(*) from pg_stat_activity where datname = current_database() and usename = 'cms_app' and wait_event_type = 'Lock'",
        );

        if ($waiting === 1) {
            return;
        }

        usleep(20_000);
    }

    throw new AssertionFailedError('No app backend waited for the lock within five seconds.');
}

/**
 * Waits until $waiters backends of the app role wait for a lock or the child has committed, for at
 * most five seconds. A test of a race uses it where the store under test must wait: the child that
 * did not wait has committed, and the test's assertions show what it did instead of a timeout.
 */
function waitForReceiptLockWaiterOrCommit(ChildProcess $child, int $waiters = 1): void
{
    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $waiting = DB::connection()->scalar(
            "select count(*) from pg_stat_activity where datname = current_database() and usename = 'cms_app' and wait_event_type = 'Lock'",
        );

        if ($waiting === $waiters || in_array('committed', $child->signals(), true)) {
            return;
        }

        usleep(20_000);
    }

    throw new AssertionFailedError(sprintf("The child neither waited for a lock nor committed within five seconds.\nSignals: %s", implode(', ', $child->signals())));
}

/**
 * The advisory locks the backend of $connection holds in this checkout's database.
 */
function heldAdvisoryLocks(Connection $connection): int
{
    $held = $connection->scalar(
        "select count(*) from pg_locks where locktype = 'advisory' and granted and pid = pg_backend_pid() and database = (select oid from pg_database where datname = current_database())",
    );

    return is_int($held) ? $held : -1;
}

/**
 * The advisory locks any backend holds in this checkout's database.
 */
function advisoryLocksInDatabase(): int
{
    $held = ReceiptTables::owner()->scalar(
        "select count(*) from pg_locks where locktype = 'advisory' and granted and database = (select oid from pg_database where datname = current_database())",
    );

    return is_int($held) ? $held : -1;
}

/**
 * Starts a child process that runs $work against its own PostgresReceiptStore, as the app role,
 * with a FakeClock at $at, inside a transaction it commits. The child signals `begun` first and
 * `committed` last.
 *
 * @param  string  $work  'mark' acknowledges the projection edge at $at; 'store' stores the fixture receipt
 *                        of 2026-01-01T00:00:00Z in the retention class $retention and signals `stored`,
 *                        `duplicate` or, for UnsupportedIsolation, `refused`
 * @param  string  $retention  the value of a RetentionClass, for 'store'
 * @param  string|null  $isolation  the isolation level the transaction is set to right after it begins;
 *                                  null keeps the connection's, READ COMMITTED
 */
function receiptChild(string $work, string $id, string $at, string $retention = 'standard', ?string $isolation = null): ChildProcess
{
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($work, $id, $at, $retention, $isolation): void {
        $connection = $context->connection();
        $resolver = new ConnectionResolver(['child' => $connection]);
        $resolver->setDefaultConnection('child');
        $clock = new FakeClock(new DateTimeImmutable($at));
        $store = new PostgresReceiptStore($resolver, $clock);
        $changesetId = ChangesetId::fromString($id);

        $connection->beginTransaction();

        if ($isolation !== null) {
            $connection->statement("set transaction isolation level {$isolation}");
        }

        $context->signal('begun');

        if ($work === 'mark') {
            $marked = $store->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now()));
            $context->signal($marked ? 'marked' : 'not-marked');
        } else {
            try {
                $store->store(ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::from($retention)));
                $context->signal('stored');
            } catch (DuplicateReceipt) {
                $context->signal('duplicate');
            } catch (UnsupportedIsolation) {
                $context->signal('refused');
            }
        }

        // The transaction is still usable: a duplicate or a lost race did not abort it.
        $connection->select('select 1');

        $connection->commit();
        $context->signal('committed');
    });

    $child->waitForSignal('begun');

    return $child;
}

it('commits a receipt together with the caller\'s own write in one transaction', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $writer = $harness->session();
    $reader = $harness->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    $writer->begin();
    $writer->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'changeset']);
    $writer->receipts()->store($receipt);

    expect($reader->receipts()->find($changesetId))->toBeNull()
        ->and(ReceiptTables::callerRows())->toBe(0);

    $writer->commit();

    expect($reader->receipts()->find($changesetId))->toEqual($receipt)
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(1)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(3)
        ->and(ReceiptTables::callerRows())->toBe(1);
});

it('leaves neither the receipt, its projection rows nor the caller\'s write after an outer rollback', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    $session->begin();
    $session->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'changeset']);
    $session->receipts()->store($receipt);
    $session->receipts()->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now()));
    $session->rollBack();

    expect(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(0)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(0)
        ->and(ReceiptTables::callerRows())->toBe(0)
        ->and($session->receipts()->find($changesetId))->toBeNull();
});

it('makes no call outside Postgres inside the transaction: no queue job, no HTTP request, no Valkey key', function (): void {
    Queue::fake();
    Http::fake();
    Http::preventStrayRequests();

    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $valkeyKeys = app(ValkeyRun::class)->keys();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    /** @var list<string> $statements */
    $statements = [];
    $session->connection->listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $session->begin();
    $session->receipts()->store($receipt);
    $session->receipts()->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('search'), $clock->now()));
    $session->receipts()->find($changesetId);

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect(app(ValkeyRun::class)->keys())->toBe($valkeyKeys)
        ->and($valkeyKeys)->toBe([])
        ->and($statements)->not->toBeEmpty();

    // The only Postgres functions the store calls: the transaction-scoped lock on the changeset,
    // taken at READ COMMITTED, once per store(). Every other statement reads or writes the receipt
    // tables only; the insert of a receipt and its projections is one statement that starts with
    // its CTEs.
    $lock = "select current_setting('transaction_isolation') as isolation, case when current_setting('transaction_isolation') = ? then pg_advisory_xact_lock(?) end as locked";
    $locks = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'pg_') || str_contains($sql, 'current_setting')));

    expect($locks)->toBe([$lock])
        ->and(PostgresReceiptStore::LOCK_CHANGESET)->toBe($lock);

    foreach (array_diff($statements, $locks) as $sql) {
        preg_match_all('/\b(?:from|into|update) "([a-z_]+)"/', $sql, $tables);

        expect($sql)->toMatch('/^(select|insert|update|with) /')
            ->and($sql)->not->toMatch('/notify|listen|dblink|copy|savepoint|pg_/i')
            ->and($tables[1])->not->toBeEmpty()
            ->and(array_diff($tables[1], [PostgresReceiptStore::RECEIPTS, PostgresReceiptStore::PROJECTIONS]))->toBe([]);
    }

    $session->commit();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(app(ValkeyRun::class)->keys())->toBe([]);
});

it('lets two workers mark different projections of one changeset at the same time, and keeps both', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;
    $harness->session()->storeCommitted($receipt);

    $a = $harness->session();
    $b = $harness->session();
    // One row per projection: B never waits for A. With a shared row it would, and fail here.
    $b->connection->statement("set lock_timeout = '1s'");

    $edge = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now());
    $search = ProjectionStatus::acknowledged(new ProjectionName('search'), $clock->advance(new DateInterval('PT1S')));

    $a->begin();
    expect($a->receipts()->markProjection($changesetId, $edge))->toBeTrue();

    $b->begin();
    expect($b->receipts()->markProjection($changesetId, $search))->toBeTrue();

    $b->commit();
    $a->commit();

    $found = $harness->session()->receipts()->find($changesetId);

    expect($found?->projections)->toEqual([
        $edge,
        ProjectionStatus::pending(new ProjectionName('fragments')),
        $search,
    ])->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(3);
});

it('makes a second worker on the same projection wait for the first, and keeps the first acknowledgement', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;
    $harness->session()->storeCommitted($receipt);

    $first = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now());
    $a = $harness->session();
    $a->begin();
    expect($a->receipts()->markProjection($changesetId, $first))->toBeTrue();

    $child = receiptChild('mark', $changesetId->toString(), '2026-01-01T00:00:09Z');
    waitForReceiptLockWaiter();

    expect($child->signals())->toBe(['begun']);

    $a->commit();
    $child->waitForSignal('committed');

    expect($child->signals())->toBe(['begun', 'marked', 'committed'])
        ->and($harness->session()->receipts()->find($changesetId)?->projections[0])->toEqual($first);
});

it('gives one receipt and one DuplicateReceipt for two concurrent stores, without aborting the loser\'s transaction', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    $a = $harness->session();
    $a->begin();
    $a->receipts()->store($receipt);

    // The child waits for A's lock on the changeset, and its lookup, a new statement, sees A's row
    // once A commits.
    $child = receiptChild('store', $changesetId->toString(), '2026-01-01T00:00:01Z');
    waitForReceiptLockWaiter();
    $a->commit();
    $child->waitForSignal('committed');

    expect($child->signals())->toBe(['begun', 'duplicate', 'committed'])
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(1)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(3);
});

it('gives one receipt and one DuplicateReceipt for two concurrent stores of one changeset in different retention classes', function (string $first, string $second): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-31T23:59:59Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::from($first));
    $changesetId = $receipt->changesetId;

    $a = $harness->session();
    $a->begin();
    $a->receipts()->store($receipt);

    // The primary key holds the retention class, so no key conflict makes the child wait: only
    // the store's lock on the changeset does. Without it, the child's lookup misses A's
    // uncommitted row, its insert goes into the other class's partition, and both commit.
    $child = receiptChild('store', $changesetId->toString(), '2026-01-01T00:00:01Z', $second);
    waitForReceiptLockWaiterOrCommit($child);
    $a->commit();
    $child->waitForSignal('committed');

    expect($child->signals())->toBe(['begun', 'duplicate', 'committed'])
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(1)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(3)
        ->and($harness->session()->receipts()->find($changesetId))->toEqual($receipt);
})->with([
    'standard first, evidence second' => ['standard', 'evidence'],
    'evidence first, standard second' => ['evidence', 'standard'],
]);

it('refuses a store inside a transaction above READ COMMITTED, so a store waiting on the changeset cannot add a second receipt', function (string $second, string $isolation): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-31T23:59:59Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    $holder = $harness->session();
    $holder->begin();
    $holder->receipts()->store($receipt);

    // The child's snapshot is taken by its first statement, at the latest the lock statement,
    // before it waits for the holder. A lookup on that snapshot misses the holder's receipt once it
    // commits: in the other class the insert then stores a second receipt, in the same class it
    // fails to serialise. The store must refuse the level before it waits instead.
    $child = receiptChild('store', $changesetId->toString(), '2026-01-01T00:00:01Z', $second, isolation: $isolation);
    waitForReceiptLockWaiterOrCommit($child);
    $holder->commit();
    $child->waitForSignal('committed');

    expect($child->signals())->toBe(['begun', 'refused', 'committed'])
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(1)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(3)
        ->and($harness->session()->receipts()->find($changesetId))->toEqual($receipt);
})->with([
    'the other retention class' => ['evidence'],
    'the same retention class' => ['standard'],
])->with([
    'repeatable read' => ['repeatable read'],
    'serializable' => ['serializable'],
]);

it('throws UnsupportedIsolation above READ COMMITTED before it takes the lock, and leaves the transaction usable', function (string $isolation): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');

    $session->begin();
    $session->connection->statement("set transaction isolation level {$isolation}");

    try {
        $session->receipts()->store($receipt);
        throw new AssertionFailedError('A store ran outside READ COMMITTED.');
    } catch (UnsupportedIsolation $unsupported) {
        expect($unsupported->getMessage())->toContain(strtoupper($isolation));
    }

    expect(heldAdvisoryLocks($session->connection))->toBe(0);

    $session->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'after the refusal']);
    $session->commit();

    expect(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $receipt->changesetId))->toBe(0)
        ->and(ReceiptTables::callerRows())->toBe(1);
})->with(['repeatable read', 'serializable']);

it('refuses a store outside a transaction before its first statement, so no lock is left on a backend behind a pooler in transaction mode', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    $session = $harness->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    // A pooler in transaction mode (PRD 5.10) runs each statement outside a transaction on any free
    // server connection. Here the session moves to the other backend after every statement, so a
    // lock one statement takes and a later one releases would be released on the wrong backend and
    // stay held on the first until the pooler closes it.
    [$other] = app(IndependentConnections::class)->open(1);
    $backends = [$session->connection->getPdo(), $other->getPdo()];

    /** @var list<string> $statements */
    $statements = [];
    $session->connection->listen(static function (QueryExecuted $query) use ($session, $backends, &$statements): void {
        $statements[] = $query->sql;
        $session->connection->setPdo($backends[count($statements) % 2]);
    });

    expect($session->inTransaction())->toBeFalse()
        ->and(fn () => $session->receipts()->store($receipt))->toThrow(TransactionRequired::class, 'Nothing was stored.')
        ->and($statements)->toBe([])
        ->and(advisoryLocksInDatabase())->toBe(0)
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(0)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(0);

    // Nothing holds the changeset: a store in another connection's transaction does not wait.
    $writer = $harness->session();
    $writer->connection->statement("set lock_timeout = '1s'");
    $writer->storeCommitted($receipt);

    expect($writer->receipts()->find($changesetId))->toEqual($receipt)
        ->and(advisoryLocksInDatabase())->toBe(0);
});

it('holds the lock on the changeset only in the caller\'s transaction, also when store() throws, and writes the receipt in one statement', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');

    /** @var list<string> $statements */
    $statements = [];
    $session->connection->listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    // The lock, the lookup of a receipt of either class, and one insert of the receipt and its
    // projections.
    $session->begin();
    $session->receipts()->store($receipt);

    expect($statements)->toHaveCount(3)
        ->and($statements[0])->toBe(PostgresReceiptStore::LOCK_CHANGESET)
        ->and($statements[1])->toStartWith('select exists(select * from "receipts"')
        ->and($statements[2])->toStartWith('with receipt as (')
        ->and(heldAdvisoryLocks($session->connection))->toBe(1);

    expect(fn () => $session->receipts()->store($receipt))->toThrow(DuplicateReceipt::class)
        ->and(heldAdvisoryLocks($session->connection))->toBe(1);

    $session->commit();

    expect(heldAdvisoryLocks($session->connection))->toBe(0);

    $session->begin();

    expect(fn () => $session->receipts()->store(ReceiptTables::receipt('2040-06-01T00:00:00Z')))->toThrow(PartitionMissing::class);

    $session->rollBack();

    expect(heldAdvisoryLocks($session->connection))->toBe(0)
        ->and(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'pg_advisory') && $sql !== PostgresReceiptStore::LOCK_CHANGESET))->toBe([]);
});

it('reports a duplicate inside the caller\'s transaction and leaves the transaction usable', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;

    $session->begin();
    $session->receipts()->store($receipt);

    expect(static fn () => $session->receipts()->store($receipt))->toThrow(DuplicateReceipt::class);

    $session->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'after the duplicate']);
    $session->commit();

    expect(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(1)
        ->and(ReceiptTables::callerRows())->toBe(1);
});

it('scans at most one standard and one evidence leaf partition to find a receipt', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-02-20T00:00:00Z'), new DateTimeImmutable('2026-03-10T00:00:00Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-03-06T12:00:00Z'));
    $session = PostgresReceiptSessions::at($clock)->session();

    $standard = ReceiptTables::receipt('2026-03-05T08:00:00Z');
    $evidence = ReceiptTables::receipt('2026-02-27T08:00:00Z', RetentionClass::Evidence);
    $session->storeCommitted($standard, $evidence);

    /** @var list<array{sql: string, bindings: list<mixed>}> $queries */
    $queries = [];
    $session->connection->listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => array_values($query->bindings)];
    });

    expect($session->receipts()->find($standard->changesetId))->toEqual($standard)
        ->and($session->receipts()->find($evidence->changesetId))->toEqual($evidence)
        ->and($queries)->toHaveCount(4);

    $leaves = array_map(
        static fn (array $query): array => ReceiptTables::scannedLeaves($session->connection, $query['sql'], $query['bindings']),
        $queries,
    );

    // Without pruning, each receipts query would scan every one of these.
    $standardLeaves = ReceiptTables::owner()->scalar("select count(*) from pg_partition_tree('receipts_standard') where isleaf");

    expect($standardLeaves)->toBeGreaterThan(15)
        ->and($leaves)->toBe([
            ['receipts_evidence_p202603', 'receipts_standard_p20260305'],
            ['receipt_projections_standard_p20260305'],
            ['receipts_evidence_p202602', 'receipts_standard_p20260227'],
            ['receipt_projections_evidence_p202602'],
        ]);
});

it('throws PartitionMissing for a changeset at a date without a partition', function (): void {
    $session = PostgresReceiptSessions::at(new FakeClock)->session();
    $receipt = ReceiptTables::receipt('2040-06-01T00:00:00Z');

    $session->begin();

    try {
        $session->receipts()->store($receipt);
        throw new AssertionFailedError('The store wrote a receipt with no partition for its date.');
    } catch (PartitionMissing $missing) {
        expect($missing->getMessage())->toContain('receipts_standard')
            ->and($missing->getPrevious())->toBeInstanceOf(QueryException::class);
    }

    $session->rollBack();
});

it('expires a standard receipt one microsecond after its expiry instant', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00.250Z');
    $changesetId = $receipt->changesetId;
    $session->storeCommitted($receipt);

    $clock->set(new DateTimeImmutable('2026-01-08T00:00:00.250000Z'));
    expect($session->receipts()->find($changesetId))->toEqual($receipt)
        ->and($session->receipts()->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('edge'))))->toBeTrue();

    $clock->set(new DateTimeImmutable('2026-01-08T00:00:00.250001Z'));
    expect($session->receipts()->find($changesetId))->toBeNull()
        ->and($session->receipts()->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('edge'))))->toBeFalse();
});

it('writes one row per projection and none for a receipt without projections', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $with = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $without = new StoredReceipt(ReceiptTables::receipt('2026-01-01T00:00:00Z', sequence: 1)->changesetId, RetentionClass::Standard);
    $session->storeCommitted($with, $without);

    $states = ReceiptTables::owner()->table(PostgresReceiptStore::PROJECTIONS)
        ->where('changeset_id', $with->changesetId->toString())
        ->orderBy('projection')
        ->pluck('state', 'projection')
        ->all();

    expect($states)->toBe(['edge' => 'pending', 'fragments' => 'pending', 'search' => 'pending'])
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $without->changesetId))->toBe(0)
        ->and($session->receipts()->find($without->changesetId))->toEqual($without);
});

it('is the ReceiptStore the container resolves, on the default connection and the application Clock', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    app()->instance(Clock::class, $clock);
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('PT1S'));
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');

    $store = app(ReceiptStore::class);
    ReceiptTables::commit(DB::connection(), $store, $receipt);

    expect($store)->toBeInstanceOf(PostgresReceiptStore::class)
        ->and(app(ReceiptStore::class))->toBe($store)
        ->and(DB::connection()->table(PostgresReceiptStore::RECEIPTS)->count())->toBe(1)
        ->and($store->find($receipt->changesetId))->toEqual($receipt);

    $clock->advance(new DateInterval('P8D'));

    expect($store->find($receipt->changesetId))->toBeNull();
});

it('gives the app role no DELETE, TRUNCATE or UPDATE of a receipt, not even on a partition directly', function (string $sql): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z'));

    try {
        DB::connection()->statement($sql);
        throw new AssertionFailedError(sprintf('The app role ran: %s', $sql));
    } catch (QueryException $exception) {
        expect(SqlError::of($exception)->sqlState)->toBe('42501');
    }
})->with([
    'delete a receipt' => ['delete from receipts'],
    'update a receipt' => ["update receipts set retention_class = 'standard'"],
    'truncate receipts' => ['truncate receipts'],
    'delete a projection row' => ['delete from receipt_projections'],
    'truncate projection rows' => ['truncate receipt_projections'],
    'delete from a list partition' => ['delete from receipts_standard'],
    'delete from a leaf partition' => ['delete from receipts_standard_p20260101'],
    'update a leaf partition of receipts' => ['update receipts_evidence_p202601 set changeset_id = changeset_id'],
    'delete from a projection leaf' => ['delete from receipt_projections_evidence_p202601'],
]);

it('lets the app role read and write what the store needs, through the parents', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('PT1S'));
    $store = new PostgresReceiptStore(app('db'), $clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::Evidence);

    ReceiptTables::commit(DB::connection(), $store, $receipt);

    expect($store->markProjection($receipt->changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now())))->toBeTrue()
        ->and($store->find($receipt->changesetId)?->projections[0]->state)->toBe(ProjectionState::Acknowledged)
        ->and(DB::connection()->scalar('select current_user'))->toBe('cms_app');
});

/**
 * A receipt store on an app-role connection whose read PDO is a lagging read replica: a second
 * backend that holds a REPEATABLE READ snapshot taken now, so it never sees what commits later.
 * Outside a transaction Laravel sends a select to the read PDO, and a zero-row update does not make
 * the connection sticky, so every read the store makes must ask for the write PDO itself.
 *
 * @return array{PostgresReceiptStore, PostgresConnection, PostgresConnection} the store, the replica and the primary
 */
function receiptStoreBehindLaggingReplica(Clock $clock): array
{
    [$primary, $replica] = app(IndependentConnections::class)->open(2);

    $replica->beginTransaction();
    $replica->statement('set transaction isolation level repeatable read');
    $replica->scalar(sprintf('select count(*) from %s', PostgresReceiptStore::RECEIPTS));
    $primary->setReadPdo($replica->getPdo());

    return [new PostgresReceiptStore(app(DatabaseManager::class), $clock, $primary->getName()), $replica, $primary];
}

it('reads the primary and not a lagging read replica in find() and markProjection() outside a transaction', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    [$store, $replica] = receiptStoreBehindLaggingReplica($clock);
    $receipt = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $changesetId = $receipt->changesetId;
    $harness->session()->storeCommitted($receipt);
    $edge = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now());

    // The replica has not replayed the receipt; the primary has it.
    expect($replica->table(PostgresReceiptStore::RECEIPTS)->where('changeset_id', $changesetId->toString())->exists())->toBeFalse()
        ->and($store->find($changesetId))->toEqual($receipt);

    // The first acknowledgement updates the row on the primary; the second and a pending status
    // update nothing and must still find the live receipt that lists the projection.
    expect($store->markProjection($changesetId, $edge))->toBeTrue()
        ->and($store->markProjection($changesetId, $edge))->toBeTrue()
        ->and($store->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('search'))))->toBeTrue()
        ->and($store->find($changesetId)?->projections[0]->state)->toBe(ProjectionState::Acknowledged)
        ->and($store->find($changesetId)?->projections[2]->state)->toBe(ProjectionState::Pending);
});

it('reads the primary and not a lagging read replica when store() looks for a receipt of the other class', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresReceiptSessions::at($clock);
    [$store, $replica, $primary] = receiptStoreBehindLaggingReplica($clock);
    $standard = ReceiptTables::receipt('2026-01-01T00:00:00Z');
    $harness->session()->storeCommitted($standard);

    $primary->beginTransaction();

    expect($replica->table(PostgresReceiptStore::RECEIPTS)->where('changeset_id', $standard->changesetId->toString())->exists())->toBeFalse()
        ->and(fn () => $store->store(ReceiptTables::receipt('2026-01-01T00:00:00Z', RetentionClass::Evidence)))->toThrow(DuplicateReceipt::class);

    $primary->rollBack();

    expect(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $standard->changesetId))->toBe(1)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $standard->changesetId))->toBe(3);
});

it('stores nothing of a receipt whose projections have no partition, and stores all of it after the rollback', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2031-07-01T00:00:01Z'));
    $session = PostgresReceiptSessions::at($clock)->session();
    $owner = ReceiptTables::owner();
    $owner->statement("set lock_timeout = '5s'");
    $owner->statement('drop table if exists receipt_projections_standard_p20310701');
    $owner->statement('reset lock_timeout');

    $changesetId = ReceiptTables::receipt('2031-07-01T00:00:00Z')->changesetId;
    $receipt = new StoredReceipt($changesetId, RetentionClass::Standard, [ProjectionStatus::pending(new ProjectionName('edge'))]);

    $session->begin();

    expect(fn () => $session->receipts()->store($receipt))->toThrow(PartitionMissing::class, 'receipt_projections_standard');

    $session->rollBack();

    expect($session->receipts()->find($changesetId))->toBeNull()
        ->and(ReceiptTables::rows(PostgresReceiptStore::RECEIPTS, $changesetId))->toBe(0)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(0)
        ->and(heldAdvisoryLocks($session->connection))->toBe(0);

    // Once the partition exists, the retry stores the whole receipt: nothing of the failed store holds the changeset.
    app(PartitionFixtures::class)->cover($clock->now(), $clock->now());
    $session->storeCommitted($receipt);

    expect($session->receipts()->find($changesetId))->toEqual($receipt)
        ->and(ReceiptTables::rows(PostgresReceiptStore::PROJECTIONS, $changesetId))->toBe(1);
});

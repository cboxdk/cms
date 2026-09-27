<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimLock;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The Postgres idempotency store beyond the shared suite (GUARDRAILS 4.1 and 9, PRD 6.1, 4, 4.1,
 * 4.2): claims that wait for another process's commit, a race of two claims, InFlight without
 * aborting the caller's transaction, InFlight before transaction_timeout ends the session, the day boundary between partitions, one row for repeated
 * claims, one transaction with the caller's own writes, no effect outside Postgres, partition
 * pruning, hash collisions and the isolation level. The shared cases run in
 * PostgresIdempotencyStoreContractTest.
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
 * Starts a child process that claims the key with its own PostgresIdempotencyStore, as the app
 * role, in a transaction at a FakeClock set to $at. A Fresh claim is completed with $changeset. The
 * child signals `begun`, then the result (`fresh`, `replay:<changeset id>`, `conflict` or
 * `in-flight`), holds the transaction for $holdMilliseconds, commits and signals `committed`.
 *
 * With a $barrier, the child first waits for a shared advisory lock on it, so the test can hold the
 * barrier and release several children at once.
 */
function idempotencyChild(string $key, string $content, int $budgetMilliseconds, ChangesetId $changeset, int $holdMilliseconds, string $at, ?int $barrier = null): ChildProcess
{
    $changesetId = $changeset->toString();

    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($key, $content, $budgetMilliseconds, $changesetId, $holdMilliseconds, $at, $barrier): void {
        $connection = $context->connection();
        $resolver = new ConnectionResolver(['child' => $connection]);
        $resolver->setDefaultConnection('child');
        $store = new PostgresIdempotencyStore($resolver, new FakeClock(new DateTimeImmutable($at)));

        $connection->beginTransaction();
        $context->signal('begun');

        if ($barrier !== null) {
            $connection->select('select pg_advisory_xact_lock_shared(?)', [$barrier]);
        }

        $result = $store->claim(IdempotencyTables::scope(), new IdempotencyKey($key), ContentHash::of($content), WaitBudget::milliseconds($budgetMilliseconds));

        if ($result instanceof Fresh) {
            $store->complete($result->token, ChangesetId::fromString($changesetId));
        }

        $context->signal(match (true) {
            $result instanceof Fresh => 'fresh',
            $result instanceof Replay => 'replay:'.$result->changesetId->toString(),
            $result instanceof Conflict => 'conflict',
            default => 'in-flight',
        });

        usleep($holdMilliseconds * 1000);
        $connection->select('select 1');
        $connection->commit();
        $context->signal('committed');
    });

    $child->waitForSignal('begun');

    return $child;
}

/**
 * Waits until $count backends of the app role wait for a lock, for at most five seconds.
 */
function waitForIdempotencyLockWaiters(int $count): void
{
    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $waiting = DB::connection()->scalar(
            "select count(*) from pg_stat_activity where datname = current_database() and usename = 'cms_app' and wait_event_type = 'Lock'",
        );

        if ($waiting === $count) {
            return;
        }

        usleep(20_000);
    }

    throw new AssertionFailedError(sprintf('%d app backends did not wait for a lock within five seconds.', $count));
}

function claimDefault(PostgresIdempotencySession $session, int $budgetMilliseconds = 0, ?IdempotencyKey $key = null, ?ContentHash $hash = null): ClaimResult
{
    return $session->idempotency()->claim(IdempotencyTables::scope(), $key ?? IdempotencyTables::key(), $hash ?? IdempotencyTables::hash(), WaitBudget::milliseconds($budgetMilliseconds));
}

function elapsedMilliseconds(int $startedAt): float
{
    return (hrtime(true) - $startedAt) / 1_000_000;
}

it('waits for another process\'s claim and replays its changeset once that transaction commits', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00Z');

    $a = idempotencyChild('retry-me', '{"value":"A"}', 0, $changeset, 1000, '2026-01-01T00:00:01Z');
    $a->waitForSignal('fresh');

    $b = $harness->session();
    $b->begin();
    $started = hrtime(true);
    $result = claimDefault($b, 3000);
    $waited = elapsedMilliseconds($started);
    $a->waitForSignal('committed');

    expect($result)->toBeInstanceOf(Replay::class)
        ->and($result instanceof Replay ? $result->changesetId->toString() : null)->toBe($changeset->toString())
        ->and($waited)->toBeGreaterThanOrEqual(900.0)
        ->and($waited)->toBeLessThan(3000.0)
        ->and($a->signals())->toBe(['begun', 'fresh', 'committed'])
        ->and($b->inTransaction())->toBeTrue();

    $b->commit();

    expect(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(1);
});

it('gives a claim with another content hash a Conflict once the holder commits', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00Z');

    $a = idempotencyChild('retry-me', '{"value":"A"}', 0, $changeset, 300, '2026-01-01T00:00:01Z');
    $a->waitForSignal('fresh');

    $b = $harness->session();
    $b->begin();
    $result = claimDefault($b, 3000, hash: IdempotencyTables::hash('{"value":"B"}'));
    $a->waitForSignal('committed');

    expect($result)->toBeInstanceOf(Conflict::class)
        ->and($a->signals())->toBe(['begun', 'fresh', 'committed'])
        ->and(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(1);
});

it('never gives two Fresh claims when two processes race for one key with different hashes', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    PostgresIdempotencySessions::at($clock);
    $barrier = 7_140_014;

    foreach (['race-1', 'race-2', 'race-3'] as $round => $key) {
        // The test holds the barrier; both children wait for it, then claim at the same moment.
        DB::connection()->select('select pg_advisory_lock(?)', [$barrier]);
        $children = [
            idempotencyChild($key, '{"value":"A"}', 3000, IdempotencyTables::changeset('2026-01-01T00:00:00Z', $round * 2), 200, '2026-01-01T00:00:01Z', $barrier),
            idempotencyChild($key, '{"value":"B"}', 3000, IdempotencyTables::changeset('2026-01-01T00:00:00Z', $round * 2 + 1), 200, '2026-01-01T00:00:01Z', $barrier),
        ];
        waitForIdempotencyLockWaiters(2);
        DB::connection()->select('select pg_advisory_unlock(?)', [$barrier]);

        $results = [];

        foreach ($children as $child) {
            $child->waitForSignal('committed');
            $results[] = $child->signals()[1] ?? null;
        }

        sort($results);

        expect($results)->toBe(['conflict', 'fresh'], "Round {$key}")
            ->and(IdempotencyTables::rows(new IdempotencyKey($key)))->toBe(1);
    }
});

it('returns InFlight within the budget while another process holds the claim, and leaves the transaction usable', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);

    $a = idempotencyChild('retry-me', '{"value":"A"}', 0, IdempotencyTables::changeset('2026-01-01T00:00:00Z'), 2000, '2026-01-01T00:00:01Z');
    $a->waitForSignal('fresh');

    $b = $harness->session();
    $b->begin();
    $started = hrtime(true);
    $result = claimDefault($b, 200);
    $waited = elapsedMilliseconds($started);

    expect($result)->toBeInstanceOf(InFlight::class)
        ->and($waited)->toBeGreaterThanOrEqual(200.0)
        ->and($waited)->toBeLessThan(1000.0)
        ->and($b->connection->scalar('select 1'))->toBe(1)
        ->and(IdempotencyTables::locks(IdempotencyTables::scope(), IdempotencyTables::key()))->toBe(1)
        ->and($a->signals())->toBe(['begun', 'fresh']);

    $b->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'after in flight']);
    $b->commit();

    expect(ReceiptTables::callerRows())->toBe(1);
});

it('returns InFlight before the app role\'s transaction_timeout ends a claim whose transaction began just before the holder\'s', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $waiter = $harness->session();
    $holder = $harness->session();

    // A double submit: the waiter's transaction begins first, the holder's 20 ms later, and the
    // holder claims the key and sits on it. The role's transaction_timeout (5 s) would end the
    // waiter's session before a 5000 ms budget runs out.
    $waiter->begin();
    usleep(20_000);
    $holder->begin();
    expect(claimDefault($holder))->toBeInstanceOf(Fresh::class);

    $timeout = $waiter->connection->scalar("select setting::bigint from pg_settings where name = 'transaction_timeout'");
    $started = hrtime(true);
    $result = claimDefault($waiter, WaitBudget::MAX_MILLISECONDS);
    $waited = elapsedMilliseconds($started);

    expect($timeout)->toBe(5000)
        ->and($result)->toBeInstanceOf(InFlight::class)
        ->and($waited)->toBeGreaterThanOrEqual(4000.0)
        ->and($waited)->toBeLessThan(5000.0 - PostgresIdempotencyStore::TRANSACTION_MARGIN_MILLISECONDS / 2)
        ->and($waiter->connection->scalar('select 1'))->toBe(1);

    $waiter->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'after in flight']);
    $waiter->commit();
    $holder->rollBack();

    expect(ReceiptTables::callerRows())->toBe(1);
});

it('ends the wait at the transaction\'s own transaction_timeout, less the margin, and waits the whole budget without one', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $holder = $harness->session();
    $holder->connection->statement("set transaction_timeout = '0'");
    $holder->begin();
    expect(claimDefault($holder))->toBeInstanceOf(Fresh::class);

    // A shorter timeout than the role's, set on the session before the transaction begins.
    $short = $harness->session();
    $short->connection->statement("set transaction_timeout = '1500ms'");
    $short->begin();
    $started = hrtime(true);
    $result = claimDefault($short, 3000);
    $waited = elapsedMilliseconds($started);

    expect($result)->toBeInstanceOf(InFlight::class)
        ->and($waited)->toBeGreaterThanOrEqual(1500.0 - PostgresIdempotencyStore::TRANSACTION_MARGIN_MILLISECONDS - 50)
        ->and($waited)->toBeLessThan(1500.0 - PostgresIdempotencyStore::TRANSACTION_MARGIN_MILLISECONDS / 2)
        ->and($short->connection->scalar('select 1'))->toBe(1);

    $short->rollBack();

    // With transaction_timeout off, the budget alone decides.
    $unbounded = $harness->session();
    $unbounded->connection->statement("set transaction_timeout = '0'");
    $unbounded->begin();
    $started = hrtime(true);
    $result = claimDefault($unbounded, 1200);
    $waited = elapsedMilliseconds($started);

    expect($result)->toBeInstanceOf(InFlight::class)
        ->and($waited)->toBeGreaterThanOrEqual(1200.0)
        ->and($waited)->toBeLessThan(2000.0);

    $unbounded->rollBack();
    $holder->rollBack();
});

it('replays a key completed just before midnight UTC just after it, from the previous day\'s partition', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T23:59:59.900Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-01T23:59:59.900Z');

    $writer = $harness->session();
    $writer->begin();
    $fresh = claimDefault($writer);
    expect($fresh)->toBeInstanceOf(Fresh::class);
    $writer->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    $writer->commit();

    $clock->set(new DateTimeImmutable('2026-01-02T00:00:00.100Z'));
    $reader = $harness->session();
    $reader->begin();
    $result = claimDefault($reader);

    expect($result)->toBeInstanceOf(Replay::class)
        ->and($result instanceof Replay ? $result->changesetId->toString() : null)->toBe($changeset->toString())
        ->and(IdempotencyTables::partitionsOf(IdempotencyTables::key()))->toBe(['idempotency_keys_p20260101'])
        ->and(PostgresIdempotencyStore::lowestLiveCreatedAt($clock->now())->format('Y-m-d'))->toBe('2025-12-26');
});

it('replays a key completed just after midnight UTC for a claim whose Clock stepped back before midnight', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-02T00:00:00.100Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-02T00:00:00.100Z');

    $writer = $harness->session();
    $writer->begin();
    $fresh = claimDefault($writer);
    expect($fresh)->toBeInstanceOf(Fresh::class);
    $writer->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    $writer->commit();

    $clock->set(new DateTimeImmutable('2026-01-01T23:59:59.900Z'));
    $reader = $harness->session();
    $reader->begin();
    $result = claimDefault($reader);

    expect($result)->toBeInstanceOf(Replay::class)
        ->and($result instanceof Replay ? $result->changesetId->toString() : null)->toBe($changeset->toString())
        ->and(IdempotencyTables::partitionsOf(IdempotencyTables::key()))->toBe(['idempotency_keys_p20260102']);
});

it('replays a key whose changeset is days ahead of the Clock, as another node\'s clock can be', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T12:00:00Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-04T08:00:00.250Z');

    $writer = $harness->session();
    $writer->begin();
    $fresh = claimDefault($writer);
    $writer->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    $writer->commit();

    $reader = $harness->session();
    $reader->begin();
    $result = claimDefault($reader);

    expect($result)->toBeInstanceOf(Replay::class)
        ->and($result instanceof Replay ? $result->changesetId->toString() : null)->toBe($changeset->toString())
        ->and(IdempotencyTables::partitionsOf(IdempotencyTables::key()))->toBe(['idempotency_keys_p20260104']);
});

it('keeps exactly one row for five sequential claims with the same key and hash', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00Z');
    $results = [];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $session = $harness->session();
        $session->begin();
        $result = claimDefault($session);

        if ($result instanceof Fresh) {
            $session->idempotency()->complete($result->token, $changeset);
        }

        $session->commit();
        $clock->advance(new DateInterval('PT1S'));
        $results[] = $result::class;
    }

    expect($results)->toBe([Fresh::class, Replay::class, Replay::class, Replay::class, Replay::class])
        ->and(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(1);
});

it('commits the record together with the caller\'s own write in one transaction', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $writer = $harness->session();
    $reader = $harness->session();
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00Z');

    $writer->begin();
    $writer->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'changeset']);
    $fresh = claimDefault($writer);
    $writer->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);

    expect(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(0)
        ->and(ReceiptTables::callerRows())->toBe(0);

    $writer->commit();

    $reader->begin();
    expect(claimDefault($reader))->toEqual(new Replay($changeset))
        ->and(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(1)
        ->and(ReceiptTables::callerRows())->toBe(1)
        ->and(IdempotencyTables::claimsSetting($writer->connection))->toBe('');
});

it('leaves no row and no advisory lock after an outer rollback of a claim and complete', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();

    $session->begin();
    $session->connection->table(ReceiptTables::CALLER_TABLE)->insert(['id' => 1, 'note' => 'changeset']);
    $fresh = claimDefault($session);
    $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), IdempotencyTables::changeset('2026-01-01T00:00:00Z'));

    expect(IdempotencyTables::locks(IdempotencyTables::scope(), IdempotencyTables::key()))->toBe(1)
        ->and(IdempotencyTables::claimsSetting($session->connection))->not->toBe('');

    $session->rollBack();

    expect(IdempotencyTables::rows(IdempotencyTables::key()))->toBe(0)
        ->and(ReceiptTables::callerRows())->toBe(0)
        ->and(IdempotencyTables::locks(IdempotencyTables::scope(), IdempotencyTables::key()))->toBe(0)
        ->and(IdempotencyTables::claimsSetting($session->connection))->toBe('');

    $session->begin();
    expect(claimDefault($session))->toBeInstanceOf(Fresh::class);
});

it('makes no call outside Postgres and runs only its own statements inside the transaction', function (): void {
    Queue::fake();
    Http::fake();
    Http::preventStrayRequests();

    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();
    $valkeyKeys = app(ValkeyRun::class)->keys();

    /** @var list<string> $statements */
    $statements = [];
    $session->connection->listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $session->begin();
    $fresh = claimDefault($session);
    $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), IdempotencyTables::changeset('2026-01-01T00:00:00Z'));
    claimDefault($session);

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect(app(ValkeyRun::class)->keys())->toBe($valkeyKeys)
        ->and($valkeyKeys)->toBe([])
        ->and($statements)->not->toBeEmpty();

    $allowed = [
        '/^select current_setting\(\'transaction_isolation\'\) as isolation, case when current_setting\(\'transaction_isolation\'\) = \? then pg_try_advisory_xact_lock\(\?\) end as locked, \(select case when s\.setting::bigint > 0 then floor\(s\.setting::bigint - extract\(epoch from clock_timestamp\(\) - transaction_timestamp\(\)\) \* 1000\)::bigint end from pg_settings s where s\.name = \'transaction_timeout\'\) as transaction_remaining$/',
        '/^select current_setting\(\?, true\) as claims$/',
        '/^select set_config\(\?, \?, true\)$/',
        '/^select "content_hash", "changeset_id" from "idempotency_keys" where /',
        '/^insert into "idempotency_keys" /',
    ];

    foreach ($statements as $sql) {
        $matches = array_filter($allowed, static fn (string $pattern): bool => preg_match($pattern, $sql) === 1);

        expect($matches)->toHaveCount(1, $sql)
            ->and($sql)->not->toMatch('/savepoint|notify|listen|dblink|copy|pg_advisory_lock|lock_timeout/i');
    }

    $session->commit();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(app(ValkeyRun::class)->keys())->toBe([]);
});

it('prunes every partition older than 7 days and scans the partitions from then onward to look up a key', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-02-15T00:00:00Z'), new DateTimeImmutable('2026-03-10T00:00:00Z'));
    $clock = new FakeClock(new DateTimeImmutable('2026-03-06T12:00:00Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();

    /** @var list<array{sql: string, bindings: list<mixed>}> $lookups */
    $lookups = [];
    $session->connection->listen(static function (QueryExecuted $query) use (&$lookups): void {
        if (str_contains($query->sql, 'from "idempotency_keys"')) {
            $lookups[] = ['sql' => $query->sql, 'bindings' => array_values($query->bindings)];
        }
    });

    $session->begin();
    claimDefault($session);

    expect($lookups)->toHaveCount(1);

    $leaves = ReceiptTables::scannedLeaves($session->connection, $lookups[0]['sql'], $lookups[0]['bindings']);
    $all = ReceiptTables::owner()->scalar("select count(*) from pg_partition_tree('idempotency_keys') where isleaf");

    // Every partition before 2026-02-27, 7 days before now, is pruned. Every partition from then
    // onward is read, the runway after today included, because a record's created_at follows its
    // changeset's time, which can be ahead of now; other tests may have left later partitions.
    $names = ReceiptTables::owner()->select("select relid::regclass::text as name from pg_partition_tree('idempotency_keys') where isleaf order by 1");
    $expected = array_values(array_filter(
        array_map(static fn (mixed $row): string => is_object($row) && property_exists($row, 'name') && is_string($row->name) ? $row->name : '', $names),
        static fn (string $name): bool => $name >= 'idempotency_keys_p20260227',
    ));
    sort($expected);

    expect($all)->toBeGreaterThan(20)
        ->and($expected)->toContain('idempotency_keys_p20260227', 'idempotency_keys_p20260306', 'idempotency_keys_p20260314')
        ->and($expected)->not->toContain('idempotency_keys_p20260226')
        ->and($leaves)->toBe($expected);
});

it('only serialises two keys whose lock keys collide, and never mixes their records', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $lock = ClaimLock::of(IdempotencyTables::scope(), IdempotencyTables::key());

    // A record of another key with the same lock key, as a collision would leave it.
    ReceiptTables::owner()->table(PostgresIdempotencyStore::TABLE)->insert([
        'lock_key' => $lock->key,
        'principal_kind' => 'actor',
        'principal' => 'user:7',
        'command_type' => 'entry.release',
        'idempotency_key' => 'colliding-key',
        'content_hash' => IdempotencyTables::hash('{"value":"B"}')->value,
        'changeset_id' => IdempotencyTables::changeset('2026-01-01T00:00:00Z')->toString(),
        'expires_at' => '2026-01-08T00:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    // Another transaction holds the same lock key, as a colliding claim would.
    $colliding = $harness->session();
    $colliding->begin();
    $colliding->connection->select('select pg_advisory_xact_lock(?)', [$lock->key]);

    $session = $harness->session();
    $session->begin();
    expect(claimDefault($session, 100))->toBeInstanceOf(InFlight::class);

    $colliding->commit();

    expect(claimDefault($session))->toBeInstanceOf(Fresh::class)
        ->and(IdempotencyTables::rows(new IdempotencyKey('colliding-key')))->toBe(1);
});

it('refuses a claim outside READ COMMITTED and takes no lock', function (string $level): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();

    $session->begin();
    $session->connection->statement("set transaction isolation level {$level}");

    try {
        claimDefault($session);
        throw new AssertionFailedError('A claim ran outside READ COMMITTED.');
    } catch (InvalidClaim $invalid) {
        expect($invalid->getMessage())->toContain(strtoupper($level));
    }

    expect(IdempotencyTables::locks(IdempotencyTables::scope(), IdempotencyTables::key()))->toBe(0)
        ->and($session->connection->scalar('select 1'))->toBe(1);
})->with(['repeatable read', 'serializable']);

it('throws PartitionMissing when no partition covers the record\'s date', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2040-06-01T00:00:00Z'));
    [$connection] = app(IndependentConnections::class)->open(1);
    $session = new PostgresIdempotencySession($connection, new PostgresIdempotencyStore(app('db'), $clock, $connection->getName()));

    $session->begin();
    $fresh = claimDefault($session);

    try {
        $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), IdempotencyTables::changeset('2040-06-01T00:00:00Z'));
        throw new AssertionFailedError('The store wrote a record with no partition for its date.');
    } catch (PartitionMissing $missing) {
        expect($missing->getMessage())->toContain('idempotency_keys')
            ->and($missing->getPrevious())->toBeInstanceOf(QueryException::class);
    }
});

it('creates the record at the changeset\'s time when the Clock is behind it, and expires it with the changeset', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:05.250Z');
    $session = $harness->session();

    $session->begin();
    $fresh = claimDefault($session);
    $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    $session->commit();

    $row = ReceiptTables::owner()->selectOne("select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US') as created, to_char(expires_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US') as expires from idempotency_keys");

    expect($row)->toEqual((object) ['created' => '2026-01-01T00:00:05.250000', 'expires' => '2026-01-08T00:00:05.250000']);
});

it('is the IdempotencyStore the container resolves, on the default connection and the application Clock', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    app()->instance(Clock::class, $clock);
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P9D'));
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00Z');

    $store = app(IdempotencyStore::class);
    DB::connection()->beginTransaction();
    $fresh = $store->claim(IdempotencyTables::scope(), IdempotencyTables::key(), IdempotencyTables::hash(), WaitBudget::none());
    $store->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    DB::connection()->commit();

    expect($store)->toBeInstanceOf(PostgresIdempotencyStore::class)
        ->and(app(IdempotencyStore::class))->toBe($store)
        ->and(DB::connection()->scalar('select current_user'))->toBe('cms_app');

    DB::connection()->beginTransaction();
    expect($store->claim(IdempotencyTables::scope(), IdempotencyTables::key(), IdempotencyTables::hash(), WaitBudget::none()))->toEqual(new Replay($changeset));
    DB::connection()->rollBack();

    $clock->advance(new DateInterval('P8D'));

    DB::connection()->beginTransaction();
    expect($store->claim(IdempotencyTables::scope(), IdempotencyTables::key(), IdempotencyTables::hash(), WaitBudget::none()))->toBeInstanceOf(Fresh::class);
    DB::connection()->rollBack();
});

it('holds the advisory lock pg_locks shows for the claim, also when the lock key\'s top bit is set', function (string $key): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    $session = PostgresIdempotencySessions::at($clock)->session();
    $idempotencyKey = new IdempotencyKey($key);

    $session->begin();
    expect(claimDefault($session, key: $idempotencyKey))->toBeInstanceOf(Fresh::class)
        ->and(IdempotencyTables::locks(IdempotencyTables::scope(), $idempotencyKey))->toBe(1);

    $session->commit();

    expect(IdempotencyTables::locks(IdempotencyTables::scope(), $idempotencyKey))->toBe(0);
})->with(['a positive lock key' => ['retry-me'], 'a negative lock key' => ['e']]);

it('expires a record with its changeset\'s receipt, not with the time it was completed', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-02T00:00:00Z'));
    $harness = PostgresIdempotencySessions::at($clock);
    // The changeset is a day older than the completion, so the record is still in the window when
    // the changeset's receipt expires.
    $changeset = IdempotencyTables::changeset('2026-01-01T00:00:00.250Z');
    $session = $harness->session();

    $session->begin();
    $fresh = claimDefault($session);
    $session->idempotency()->complete($fresh instanceof Fresh ? $fresh->token : throw new AssertionFailedError('Not fresh.'), $changeset);
    $session->commit();

    $clock->set(new DateTimeImmutable('2026-01-08T00:00:00.250000Z'));
    $session->begin();
    expect(claimDefault($session))->toEqual(new Replay($changeset));
    $session->rollBack();

    $clock->set(new DateTimeImmutable('2026-01-08T00:00:00.250001Z'));
    $session->begin();
    expect(claimDefault($session))->toBeInstanceOf(Fresh::class);
    $session->rollBack();
});

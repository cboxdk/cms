<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\Infrastructure\OwnerTruncation;
use Cbox\Cms\Testkit\Postgres\PostgresHarness;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;

/*
 * The real-Postgres harness (GUARDRAILS 9; PRD 4.2): the test connection is Postgres 18 of
 * compose.yaml as the app role, the owner role builds the schema, no transaction wraps a test, and the owner
 * truncates what a test committed.
 */

it('runs on Postgres 18, the major compose.yaml runs', function (): void {
    // Pinned to the major of ghcr.io/cboxdk/postgres:18, not "at least 17": a test run on another
    // server fails here. 17 stays the minimum, which cms:doctor checks (GUARDRAILS 1.2).
    expect(DB::scalar('select version()'))->toBeString()->toStartWith('PostgreSQL 18');
});

it('runs as the app role on the default connection, which has no DDL', function (): void {
    $appRole = config('database.connections.pgsql.username');

    expect($appRole)->toBe('cms_app')
        ->and(DB::scalar('select current_user'))->toBe($appRole)
        ->and(DB::getDefaultConnection())->toBe('pgsql');

    try {
        DB::statement('create table harness_ddl_probe (x int)');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501');

        return;
    }

    Assert::fail('CREATE TABLE succeeded as the app role; it must fail with SQLSTATE 42501.');
});

it('runs the migrations as the owner role', function (): void {
    expect(Probe::owner()->scalar('select current_user'))->toBe('cms_owner')
        ->and(DB::scalar("select tableowner from pg_tables where schemaname = 'cms' and tablename = 'migrations'"))->toBe('cms_owner');
});

it('does not wrap the test in a transaction, so a write is committed at once', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $table = Probe::table();
    DB::table($table)->insert(['note' => 'committed without a wrapping transaction']);

    [$other] = app(IndependentConnections::class)->open(1);

    expect($other->table($table)->where('note', 'committed without a wrapping transaction')->count())->toBe(1);
});

it('refuses a test case that wraps each test in a transaction', function (): void {
    $wrapped = new class
    {
        use DatabaseTransactions;
    };

    expect(static fn (): PostgresHarness => PostgresHarness::start(app(), $wrapped::class, 'pgsql_owner'))
        ->toThrow(AssertionFailedError::class, 'uses '.DatabaseTransactions::class.'. Postgres tests never run inside a wrapping test transaction');
});

it('refuses to start when a connection is already inside a transaction', function (): void {
    DB::beginTransaction();

    try {
        expect(static fn (): PostgresHarness => PostgresHarness::start(app(), stdClass::class, 'pgsql_owner'))
            ->toThrow(AssertionFailedError::class, 'The connection [pgsql] is inside a transaction before the test starts.');
    } finally {
        DB::rollBack();
    }
});

it('leaves a committed row behind when the test ends', function (): void {
    $table = Probe::table();
    DB::table($table)->insert(['note' => 'left behind']);

    expect(DB::table($table)->count())->toBeGreaterThanOrEqual(1);
});

it('finds the rows of the previous test truncated by the owner, with the identity restarted', function (): void {
    $table = Probe::table();

    expect(DB::table($table)->count())->toBe(0)
        ->and(DB::table($table)->insertGetId(['note' => 'first again']))->toBe(1);
})->depends('it leaves a committed row behind when the test ends');

it('uses a backend of each role that the test does not close itself', function (): array {
    $backend = static function (Connection $connection): string {
        $backend = $connection->scalar("select pg_backend_pid()::text || '@' || backend_start::text from pg_stat_activity where pid = pg_backend_pid()");

        return is_string($backend) ? $backend : throw new LogicException('Expected the backend of the connection.');
    };

    $backends = ['app' => $backend(Probe::app()), 'owner' => $backend(Probe::owner())];

    expect($backends['app'])->toMatch('/^\d+@/')
        ->and($backends['owner'])->toMatch('/^\d+@/')
        ->and($backends['app'])->not->toBe($backends['owner']);

    return $backends;
});

it('finds the backends of the previous test closed by its tear-down, not left to the garbage collector', function (array $previous): void {
    // Each role sees the start time of its own backends only, so each looks for its own. The
    // start time tells a reused pid from the old backend.
    $alive = static function (Connection $connection, mixed $backend): bool {
        if (! is_string($backend)) {
            throw new LogicException('Expected the backend the previous test recorded.');
        }

        return $connection->scalar("select exists (select from pg_stat_activity where pid::text || '@' || backend_start::text = ?)", [$backend]) === true;
    };

    expect($previous)->toHaveKeys(['app', 'owner'])
        ->and($alive(Probe::app(), $previous['app']))->toBeFalse()
        ->and($alive(Probe::owner(), $previous['owner']))->toBeFalse();
})->depends('it uses a backend of each role that the test does not close itself');

describe('OwnerTruncation', function (): void {
    it('truncates every table in the search path as the owner, except the migration log', function (): void {
        $table = Probe::table();
        DB::table($table)->insert(['note' => 'to be truncated']);
        $migrations = DB::table('migrations')->count();

        $truncated = new OwnerTruncation(Probe::owner())->truncate();

        expect($truncated)->toContain('cms.'.Probe::TABLE)
            ->and($truncated)->not->toContain('cms.migrations')
            ->and(DB::table($table)->count())->toBe(0)
            ->and(DB::table('migrations')->count())->toBe($migrations);
    });

    it('truncates a partitioned table through its parent', function (): void {
        Probe::owner()->statement('create table if not exists harness_partitioned (id bigint not null, at int not null) partition by range (at)');
        Probe::owner()->statement('create table if not exists harness_partitioned_1 partition of harness_partitioned for values from (0) to (100)');
        DB::table('harness_partitioned')->insert(['id' => 1, 'at' => 5]);

        $truncated = new OwnerTruncation(Probe::owner())->truncate();

        expect($truncated)->toContain('cms.harness_partitioned')
            ->and($truncated)->not->toContain('cms.harness_partitioned_1')
            ->and(DB::table('harness_partitioned')->count())->toBe(0);
    });

    it('truncates one table per statement, so the owner never holds the locks of every partition at once', function (): void {
        // Regression: one TRUNCATE of every table locked every partition, index and TOAST table
        // of the schema in one transaction and failed with SQLSTATE 53200 "out of shared memory"
        // once the partitions of a suite run had piled up and other checkouts used the server.
        $owner = Probe::owner();
        $owner->statement('create table if not exists harness_partitioned (id bigint not null, at int not null) partition by range (at)');
        $owner->statement('create table if not exists harness_partitioned_1 partition of harness_partitioned for values from (0) to (100)');
        $owner->flushQueryLog();
        $owner->enableQueryLog();

        try {
            $truncated = new OwnerTruncation($owner)->truncate();
            $log = $owner->getQueryLog();
        } finally {
            $owner->disableQueryLog();
            $owner->flushQueryLog();
        }

        $statements = array_values(array_filter(
            array_map(static fn (array $entry): string => $entry['query'], $log),
            static fn (string $query): bool => str_starts_with($query, 'truncate table '),
        ));

        expect($truncated)->toContain('cms.harness_partitioned')
            ->and($truncated)->toContain('cms.'.Probe::TABLE)
            ->and($statements)->toBe(array_map(
                static fn (string $table): string => sprintf(
                    'truncate table %s restart identity cascade',
                    implode('.', array_map(static fn (string $part): string => '"'.$part.'"', explode('.', $table, 2))),
                ),
                $truncated,
            ));
    });

    it('fails with a lock timeout instead of hanging when a transaction holds a lock', function (): void {
        // The first table the truncation reaches, so the time measured is the wait for its lock
        // alone: each table has a statement of its own, and a lock on a later table would add
        // the truncation of every table before it.
        $truncation = new OwnerTruncation(Probe::owner(), lockTimeout: '200ms');
        $first = $truncation->tables()[0];
        [$holder] = app(IndependentConnections::class)->open(1);
        $holder->beginTransaction();
        $holder->statement(sprintf(
            'lock table %s in access share mode',
            implode('.', array_map(static fn (string $part): string => '"'.$part.'"', explode('.', $first, 2))),
        ));

        $started = hrtime(true);

        try {
            $truncation->truncate();
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03')
                ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0);

            return;
        } finally {
            $holder->rollBack();
        }

        Assert::fail('The truncate succeeded while another transaction held a lock on the table.');
    });

    it('cannot be run by the app role, which has no TRUNCATE privilege', function (): void {
        $table = Probe::table();
        DB::table($table)->insert(['note' => 'stays']);
        $truncation = new OwnerTruncation(Probe::app());
        $first = $truncation->tables()[0];

        try {
            $truncation->truncate();
        } catch (QueryException $exception) {
            // Each table has a statement of its own, so the refusal names the first one.
            expect($exception->getCode())->toBe('42501')
                ->and($exception->getMessage())->toContain(sprintf('permission denied for table %s', substr($first, (int) strpos($first, '.') + 1)))
                ->and(DB::table($table)->count())->toBe(1);

            return;
        }

        Assert::fail('The app role truncated a table.');
    });
});

it('lets a test case name its own owner connection', function (): void {
    $case = new class('owner connection probe') extends TestCase
    {
        use RealPostgres;

        public function ownerConnection(): string
        {
            return $this->postgresOwnerConnection();
        }
    };

    expect($case->ownerConnection())->toBe('pgsql_owner');
});

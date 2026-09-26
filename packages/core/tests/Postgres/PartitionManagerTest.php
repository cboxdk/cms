<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/*
 * The partition manager against real Postgres (PRD 4, 4.1, 4.2): it creates the runway ahead of
 * the Clock, removes partitions past retention with DETACH CONCURRENTLY and DROP, runs only as
 * the owner role, and gives up on a busy lock with LockTimeout instead of leaving a half-done
 * step behind.
 */

beforeEach(function (): void {
    PartitionScratch::create();
});

afterEach(function (): void {
    app(ChildProcesses::class)->stopAll();
    app(IndependentConnections::class)->closeAll();
    PartitionScratch::drop();
});

/**
 * Runs $callback and returns what it threw.
 *
 * @param  Closure(): mixed  $callback
 */
function thrownBy(Closure $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new AssertionFailedError('Nothing was thrown.');
}

/**
 * The names of the daily partitions of the uuid table from $first for $days days.
 *
 * @return list<string>
 */
function dailyNames(string $first, int $days): array
{
    $names = [];
    $day = new DateTimeImmutable($first);

    for ($i = 0; $i < $days; $i++) {
        $names[] = PartitionScratch::UUID_TABLE.'_p'.$day->format('Ymd');
        $day = $day->modify('+1 day');
    }

    return $names;
}

/**
 * Starts a child process that holds a lock on the uuid table in a transaction, as the owner role,
 * and returns once it holds it. The child sleeps in short statements, so when the test stops it
 * the server sees the connection close at once and releases the lock.
 */
function holdLock(string $mode): ChildProcess
{
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($mode): void {
        $connection = $context->connection();
        $connection->beginTransaction();
        $connection->statement(sprintf('lock table partition_scratch in %s mode', $mode));
        $context->signal('locked');

        for ($i = 0; $i < 600; $i++) {
            $connection->select('select pg_sleep(0.05)');
        }

        $connection->commit();
    }, 'pgsql_owner');

    $child->waitForSignal('locked');

    return $child;
}

function idAt(string $instant): string
{
    return Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($instant)))->value;
}

it('creates exactly the runway ahead of the Clock and is idempotent on a second run', function (): void {
    PartitionScratch::clockAt('2026-03-10T15:00:00.250000Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()]);

    $report = app(MaintainPartitions::class)->maintain();

    $expected = dailyNames('2026-03-10', 15);

    expect($report->role)->toBe('cms_owner')
        ->and($report->partitions(PartitionChangeKind::Created))->toBe($expected)
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe([])
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe($expected)
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(16)
        ->and($report->runways[0]->coveredUntil?->format(DATE_ATOM))->toBe('2026-03-25T00:00:00+00:00');

    $second = app(MaintainPartitions::class)->maintain();

    expect($second->changes)->toBe([])
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe($expected)
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(16);
});

it('bounds each daily partition by the lowest UUIDv7 of its first millisecond and of the next day', function (): void {
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()]);

    app(MaintainPartitions::class)->maintain();

    $lastOfDay = Uuid7::highestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable('2026-03-10T23:59:59.999Z')))->value;
    $firstOfNext = idAt('2026-03-11T00:00:00Z');

    PartitionScratch::app()->insert('insert into partition_scratch (id) values (?), (?)', [$lastOfDay, $firstOfNext]);

    expect(PartitionScratch::bounds('partition_scratch_p20260310'))
        ->toBe(sprintf("FOR VALUES FROM ('%s') TO ('%s')", idAt('2026-03-10T00:00:00Z'), idAt('2026-03-11T00:00:00Z')))
        ->and(PartitionScratch::partitionOfId($lastOfDay))->toBe('partition_scratch_p20260310')
        ->and(PartitionScratch::partitionOfId($firstOfNext))->toBe('partition_scratch_p20260311');
});

it('creates monthly partitions on a timestamp key', function (): void {
    PartitionScratch::clockAt('2026-01-20T08:00:00Z');
    PartitionScratch::manage([PartitionScratch::TIME_TABLE => PartitionScratch::daily(['key' => 'timestamp', 'interval' => 'month'])]);

    $report = app(MaintainPartitions::class)->maintain();

    PartitionScratch::app()->insert('insert into partition_scratch_ts (at) values (?)', ['2026-01-31 23:59:59.999999+00']);

    expect($report->partitions(PartitionChangeKind::Created))->toBe(['partition_scratch_ts_p202601', 'partition_scratch_ts_p202602'])
        ->and(PartitionScratch::bounds('partition_scratch_ts_p202601'))->toBe("FOR VALUES FROM ('2026-01-01 00:00:00+00') TO ('2026-02-01 00:00:00+00')")
        ->and(PartitionScratch::owner()->scalar('select tableoid::regclass::text from partition_scratch_ts'))->toBe('partition_scratch_ts_p202601');
});

it('runs its DDL as the owner role, with lock_timeout 2s set before every step', function (): void {
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 1]);
    PartitionScratch::recordStatements();

    app(MaintainPartitions::class)->maintain();

    $owner = PartitionScratch::statements('pgsql_owner');
    $ddl = array_values(array_filter($owner, static fn (string $sql): bool => preg_match('/^(create|alter|drop) table/', $sql) === 1));

    expect(PartitionScratch::ownerOf('partition_scratch_p20260310'))->toBe('cms_owner')
        ->and(PartitionScratch::ownerOf('partition_scratch_p20260311'))->toBe('cms_owner')
        ->and(PartitionScratch::statements('pgsql'))->toBe([])
        ->and($ddl)->toHaveCount(4);

    // The setting in force for each DDL statement is the last set or reset before it.
    foreach ($owner as $index => $sql) {
        if (! in_array($sql, $ddl, true)) {
            continue;
        }

        $setting = null;

        foreach (array_slice($owner, 0, $index) as $earlier) {
            if (str_starts_with($earlier, 'set lock_timeout') || $earlier === 'reset lock_timeout') {
                $setting = $earlier;
            }
        }

        expect($setting)->toBe("set lock_timeout = '2s'");
    }
});

it('gives new partitions the parent\'s row security, so reading a partition directly finds nothing', function (): void {
    PartitionScratch::owner()->statement('alter table partition_scratch enable row level security');
    PartitionScratch::owner()->statement('alter table partition_scratch force row level security');
    PartitionScratch::owner()->statement('create policy scratch_all on partition_scratch using (true) with check (true)');
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 1]);

    app(MaintainPartitions::class)->maintain();

    PartitionScratch::app()->insert('insert into partition_scratch (id) values (?)', [idAt('2026-03-10T16:00:00Z')]);

    $flags = PartitionScratch::owner()->selectOne("select relrowsecurity as rls, relforcerowsecurity as forced from pg_class where oid = 'partition_scratch_p20260310'::regclass");

    expect($flags)->toEqual((object) ['rls' => true, 'forced' => true])
        ->and(PartitionScratch::app()->scalar('select count(*) from partition_scratch'))->toBe(1)
        ->and(PartitionScratch::app()->scalar('select count(*) from partition_scratch_p20260310'))->toBe(0);
});

it('gives new partitions the parent\'s grants, not the owner\'s default privileges', function (): void {
    PartitionScratch::owner()->statement('revoke all on partition_scratch from cms_app');
    PartitionScratch::owner()->statement('grant select, insert on partition_scratch to cms_app');
    PartitionScratch::owner()->statement('grant select on partition_scratch to public');
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 1]);

    app(MaintainPartitions::class)->maintain();

    $grants = static fn (string $table): array => ReceiptTables::texts(
        PartitionScratch::owner(),
        "select case when a.grantee = 0 then 'public' else pg_get_userbyid(a.grantee)::text end || ' ' || a.privilege_type as value from pg_class c cross join lateral aclexplode(c.relacl) a where c.oid = ?::regclass and a.grantee <> c.relowner order by 1",
        [$table],
    );

    expect($grants('partition_scratch'))->toBe(['cms_app INSERT', 'cms_app SELECT', 'public SELECT'])
        ->and($grants('partition_scratch_p20260310'))->toBe($grants('partition_scratch'))
        ->and($grants('partition_scratch_p20260311'))->toBe($grants('partition_scratch'));

    try {
        PartitionScratch::app()->statement('delete from partition_scratch_p20260310');
        throw new AssertionFailedError('The app role deleted from a partition whose parent does not grant DELETE.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501');
    }
});

it('removes expired partitions with DETACH CONCURRENTLY and DROP TABLE, and never deletes rows', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 7])]);
    app(MaintainPartitions::class)->cover(new PartitionRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-14T00:00:00Z')));
    PartitionScratch::app()->insert('insert into partition_scratch (id) values (?), (?), (?)', [
        idAt('2026-01-05T10:00:00Z'),
        idAt('2026-01-12T23:59:59.999Z'),
        idAt('2026-01-13T00:00:00Z'),
    ]);

    // At 2026-01-20 with 7 days of retention, a partition has expired when its span ended at
    // 2026-01-13 or before: 2026-01-12 has, 2026-01-13 has not.
    PartitionScratch::clockAt('2026-01-20T00:00:00Z');
    PartitionScratch::recordStatements();

    $report = app(MaintainPartitions::class)->maintain();

    $expired = dailyNames('2026-01-01', 12);
    $statements = PartitionScratch::statements();
    $detaches = array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^alter table .* detach partition .* concurrently$/', $sql) === 1));
    $drops = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'drop table')));

    expect($report->partitions(PartitionChangeKind::Detached))->toBe($expired)
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe($expired)
        ->and(array_filter($expired, PartitionScratch::exists(...)))->toBe([])
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toBe([...dailyNames('2026-01-13', 2), ...dailyNames('2026-01-20', 15)])
        ->and($detaches)->toHaveCount(12)
        ->and($drops)->toHaveCount(12)
        ->and(array_filter($statements, static fn (string $sql): bool => preg_match('/\bdelete\b/i', $sql) === 1))->toBe([])
        ->and(PartitionScratch::owner()->scalar('select count(*) from partition_scratch'))->toBe(1)
        ->and(PartitionScratch::partitionOfId(idAt('2026-01-13T00:00:00Z')))->toBe('partition_scratch_p20260113');

    foreach ($expired as $partition) {
        $detach = array_search(sprintf('alter table "cms"."partition_scratch" detach partition "cms"."%s" concurrently', $partition), $statements, true);
        $drop = array_search(sprintf('drop table "cms"."%s"', $partition), $statements, true);

        expect($detach)->toBeInt()->and($drop)->toBeInt()->and($detach < $drop)->toBeTrue();
    }
});

it('keeps every partition of a table without retention', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()]);
    app(MaintainPartitions::class)->cover(new PartitionRange(new DateTimeImmutable('2020-01-01T00:00:00Z'), new DateTimeImmutable('2020-01-02T00:00:00Z')));
    PartitionScratch::clockAt('2026-01-20T00:00:00Z');

    $report = app(MaintainPartitions::class)->maintain();

    expect($report->partitions(PartitionChangeKind::Dropped))->toBe([])
        ->and(PartitionScratch::exists('partition_scratch_p20200101'))->toBeTrue();
});

it('gives up with LockTimeout while another process holds ACCESS SHARE on the parent, and leaves the partition attached', function (): void {
    PartitionScratch::manage(
        [PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 1])],
        ['runway_days' => 1, 'attempts' => 2, 'backoff_ms' => 50],
    );
    app(MaintainPartitions::class)->cover(new PartitionRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-11T00:00:00Z')));
    PartitionScratch::clockAt('2026-01-10T12:00:00Z');

    $child = holdLock('access share');
    PartitionScratch::recordStatements();

    $started = hrtime(true);
    $timeout = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    expect($timeout)->toBeInstanceOf(LockTimeout::class);
    assert($timeout instanceof LockTimeout);

    expect($timeout->step)->toBe(DdlStep::Detach)
        ->and($timeout->table)->toBe('partition_scratch')
        ->and($timeout->partition)->toBe('partition_scratch_p20260101')
        ->and($timeout->attempts)->toBe(2)
        ->and($timeout->getMessage())->toStartWith('['.LockTimeout::CODE.']')
        ->and($elapsedMs)->toBeGreaterThan(4000.0)->toBeLessThan(10_000.0)
        // Nothing half-detached: the partition is still attached and not pending, in both catalogs.
        ->and(PartitionScratch::partitions(PartitionScratch::UUID_TABLE))->toContain('partition_scratch_p20260101')
        ->and(PartitionScratch::pendingDetach(PartitionScratch::UUID_TABLE))->toBe([])
        ->and(PartitionScratch::isPartition('partition_scratch_p20260101'))->toBeTrue()
        ->and(array_filter(PartitionScratch::statements(), static fn (string $sql): bool => str_contains($sql, 'detach partition')))->toBe([]);

    $child->stop();

    $report = app(MaintainPartitions::class)->maintain();

    expect($report->partitions(PartitionChangeKind::Dropped))->toBe(dailyNames('2026-01-01', 8))
        ->and(PartitionScratch::exists('partition_scratch_p20260101'))->toBeFalse();
});

it('rolls a create back when ATTACH passes lock_timeout, so no stray table is left', function (): void {
    PartitionScratch::clockAt('2026-01-10T12:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 1, 'attempts' => 2, 'backoff_ms' => 50]);

    // SHARE conflicts with the SHARE UPDATE EXCLUSIVE lock that ATTACH PARTITION takes.
    $child = holdLock('share');

    $started = hrtime(true);
    $timeout = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    expect($timeout)->toBeInstanceOf(LockTimeout::class);
    assert($timeout instanceof LockTimeout);

    expect($timeout->step)->toBe(DdlStep::Create)
        ->and($timeout->partition)->toBe('partition_scratch_p20260110')
        ->and($timeout->getPrevious())->toBeInstanceOf(QueryException::class)
        ->and($elapsedMs)->toBeGreaterThan(4000.0)->toBeLessThan(10_000.0)
        ->and(PartitionScratch::exists('partition_scratch_p20260110'))->toBeFalse()
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(1);
});

it('finalizes a detach that an earlier run left pending, then drops the partition', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 1])], ['runway_days' => 1]);
    app(MaintainPartitions::class)->cover(new PartitionRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z')));

    // A detach that passes lock_timeout in its second phase leaves the partition pending.
    $child = holdLock('access share');

    $owner = PartitionScratch::owner();
    $owner->statement("set lock_timeout = '200ms'");
    $interrupted = thrownBy(static fn (): bool => $owner->statement('alter table partition_scratch detach partition partition_scratch_p20260101 concurrently'));
    $owner->statement('reset lock_timeout');
    $child->stop();

    expect($interrupted)->toBeInstanceOf(QueryException::class)
        ->and(PartitionScratch::pendingDetach(PartitionScratch::UUID_TABLE))->toBe(['partition_scratch_p20260101']);

    PartitionScratch::clockAt('2026-01-10T00:00:00Z');
    PartitionScratch::recordStatements();

    $report = app(MaintainPartitions::class)->maintain();

    expect($report->partitions(PartitionChangeKind::Finalized))->toBe(['partition_scratch_p20260101'])
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe(['partition_scratch_p20260101'])
        ->and(PartitionScratch::statements('pgsql_owner'))->toContain('alter table "cms"."partition_scratch" detach partition "cms"."partition_scratch_p20260101" finalize')
        ->and(PartitionScratch::pendingDetach(PartitionScratch::UUID_TABLE))->toBe([])
        ->and(PartitionScratch::exists('partition_scratch_p20260101'))->toBeFalse();
});

it('drops an expired partition that was detached but not dropped', function (): void {
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily(['retention_days' => 1])], ['runway_days' => 1]);
    app(MaintainPartitions::class)->cover(new PartitionRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z')));
    PartitionScratch::owner()->statement('alter table partition_scratch detach partition partition_scratch_p20260101');
    PartitionScratch::clockAt('2026-01-10T00:00:00Z');

    $report = app(MaintainPartitions::class)->maintain();

    expect($report->partitions(PartitionChangeKind::Detached))->toBe([])
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe(['partition_scratch_p20260101'])
        ->and(PartitionScratch::exists('partition_scratch_p20260101'))->toBeFalse();
});

it('refuses to run on the app connection, before it changes anything', function (): void {
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['owner_connection' => 'pgsql']);

    $refused = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());

    expect($refused)->toBeInstanceOf(OwnerConnectionRequired::class)
        ->and($refused->getMessage())->toStartWith('['.OwnerConnectionRequired::CODE.']')
        ->and($refused->getMessage())->toContain('[pgsql]')
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(1);
});

it('refuses a connection under another name that logs in as the app role', function (): void {
    config()->set('database.connections.pgsql_disguised', config('database.connections.pgsql'));
    PartitionScratch::clockAt('2026-03-10T15:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['owner_connection' => 'pgsql_disguised']);

    $refused = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());

    expect($refused)->toBeInstanceOf(OwnerConnectionRequired::class)
        ->and($refused->getMessage())->toContain('"cms_app"')
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(1);
});

it('lets an insert outside the runway fail, and the mapper turns it into PartitionMissing', function (): void {
    PartitionScratch::clockAt('2026-01-01T00:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()]);
    app(MaintainPartitions::class)->maintain();

    PartitionScratch::app()->insert('insert into partition_scratch (id) values (?)', [idAt('2026-01-15T23:59:59Z')]);

    $outside = thrownBy(static fn (): bool => PartitionScratch::app()->insert('insert into partition_scratch (id) values (?)', [idAt('2026-01-16T00:00:00Z')]));

    expect($outside)->toBeInstanceOf(QueryException::class);
    assert($outside instanceof QueryException);

    $mapped = MissingPartitionMapper::map($outside);

    expect($outside->getCode())->toBe('23514')
        ->and($mapped)->toBeInstanceOf(PartitionMissing::class)
        ->and($mapped->getPrevious())->toBe($outside);
    assert($mapped instanceof PartitionMissing);

    expect($mapped->table)->toBe('partition_scratch')
        ->and($mapped->getMessage())->toStartWith('['.PartitionMissing::CODE.']')
        ->and(PartitionMissing::CODE)->toBe('partition_missing');
});

it('leaves a violated CHECK constraint, the same SQLSTATE, as it is', function (): void {
    PartitionScratch::clockAt('2026-01-01T00:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 1]);
    app(MaintainPartitions::class)->maintain();

    $violation = thrownBy(static fn (): bool => PartitionScratch::app()->insert("insert into partition_scratch (id, note) values (?, 'forbidden')", [idAt('2026-01-01T10:00:00Z')]));

    assert($violation instanceof QueryException);

    expect($violation->getCode())->toBe('23514')
        ->and(MissingPartitionMapper::map($violation))->toBe($violation)
        ->and(MissingPartitionMapper::partitionMissing($violation))->toBeNull();
});

it('waits for the maintenance lock of another run within lock_timeout, then gives up', function (): void {
    PartitionScratch::clockAt('2026-01-01T00:00:00Z');
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['attempts' => 1]);
    [$other] = app(IndependentConnections::class)->open(1, 'pgsql_owner');
    $other->select('select pg_advisory_lock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);

    $timeout = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());
    $other->select('select pg_advisory_unlock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);

    expect($timeout)->toBeInstanceOf(LockTimeout::class);
    assert($timeout instanceof LockTimeout);

    expect($timeout->step)->toBe(DdlStep::Lock)
        ->and($timeout->table)->toBeNull()
        ->and($timeout->attempts)->toBe(1)
        ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(1);

    expect(app(MaintainPartitions::class)->maintain()->changes)->not->toBe([]);
});

it('refuses a table that is missing, not partitioned by range, or has a DEFAULT partition', function (): void {
    PartitionScratch::clockAt('2026-01-01T00:00:00Z');
    PartitionScratch::owner()->statement('create table partition_scratch_list (kind text not null) partition by list (kind)');
    PartitionScratch::owner()->statement('create table partition_scratch_default partition of partition_scratch_ts default');

    $cases = [
        'partition_scratch_nowhere' => 'does not exist',
        'partition_scratch_list' => 'is not partitioned by range',
        PartitionScratch::TIME_TABLE => 'has the DEFAULT partition "partition_scratch_default"',
    ];

    foreach ($cases as $table => $message) {
        PartitionScratch::manage([
            PartitionScratch::UUID_TABLE => PartitionScratch::daily(),
            $table => PartitionScratch::daily(['key' => 'timestamp']),
        ]);

        $refused = thrownBy(static fn (): mixed => app(MaintainPartitions::class)->maintain());

        expect($refused)->toBeInstanceOf(UnmanageableTable::class)
            ->and($refused->getMessage())->toContain($message)
            ->and(PartitionScratch::treeCount(PartitionScratch::UUID_TABLE))->toBe(1);
    }
});

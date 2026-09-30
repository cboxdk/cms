<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionEventLogProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\EventLagCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\IdleInTransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OldestTransactionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ParkedAggregatesCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\HeldTransaction;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The doctor's checks of the horizon and the event log on the Postgres 18 service of compose.yaml
 * (PRD 4.2, 7.4, 7.8, 7.12): idle_in_transaction_session_timeout on the app role and on a scratch
 * role without it, the open transactions pg_stat_activity shows the app role, and the event log's
 * cursors, events and parked aggregates read as the app role.
 *
 * The server is shared with every other checkout's suite, so a test asserts what its own sessions
 * cause: that its transaction is at least as old as it held it, never which pid is the oldest.
 */

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    ScratchRoles::drop();
    DB::purge(DoctorConnection::NAME);
});

function horizonEventClock(): FakeClock
{
    return new FakeClock(new DateTimeImmutable('2026-06-02T08:00:00Z'));
}

/**
 * The event log probe with a registry of one subscription of counter.raised in the lane.
 */
function horizonEventProbe(Lane $lane = Lane::Critical): EventLogProbe
{
    $registry = new FakeRegistryCache;
    $registry->write(new CompiledRegistry([], [], [], [
        new SubscriberEntry('App\Counters', 'cboxdk/cms', new SubscriptionName('test.counters'), $lane, null, [new SubscribedEvent(CounterRaised::class, CounterRaised::type())]),
        new SubscriberEntry('App\Other', 'cboxdk/cms', new SubscriptionName('test.other'), Lane::Standard, null, [new SubscribedEvent(CounterRaised::class, new EventType('counter.lowered', 1))]),
    ]));

    return new ConnectionEventLogProbe(app(DoctorConnection::class), $registry);
}

it('reads the app role\'s idle_in_transaction_session_timeout from the role', function (): void {
    $probe = app(PostgresProbe::class);
    $timeout = $probe->idleInTransactionTimeout();
    $result = new IdleInTransactionTimeoutCheck($probe)->run();

    expect($probe)->toBeInstanceOf(ConnectionPostgresProbe::class)
        ->and($timeout->role)->toBe('cms_app')
        ->and($timeout->milliseconds)->toBe(5000)
        ->and($timeout->source)->toBe(SettingSource::User)
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe('idle_in_transaction_session_timeout is 5000 ms on the app role cms_app.');
});

it('fails postgres.idle_in_transaction_timeout as blocking for a scratch role without the setting', function (): void {
    $role = ScratchRoles::login();

    $result = new IdleInTransactionTimeoutCheck(app(PostgresProbe::class))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->blocking)->toBeTrue()
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(IdleInTransactionTimeoutCheck::CODE)
        ->and($result->cause)->toBe(sprintf('idle_in_transaction_session_timeout is 0 (off) for the role %s; Postgres took the value from "default".', $role))
        ->and($result->fix)->toContain(sprintf("ALTER ROLE %s SET idle_in_transaction_session_timeout = '5s'", $role))
        ->and(DoctorExitCode::for([$result]))->toBe(DoctorExitCode::Violation);

    ScratchRoles::superuser()->statement(sprintf("alter role \"%s\" set idle_in_transaction_session_timeout = '3s'", $role));
    DB::purge(DoctorConnection::NAME);
    app()->forgetScopedInstances();

    expect(new IdleInTransactionTimeoutCheck(app(PostgresProbe::class))->run()->status)->toBe(CheckStatus::Pass);
});

it('measures how long the app role\'s open transaction has held its transaction id and its snapshot', function (): void {
    [$writer, $reader] = app(IndependentConnections::class)->open(2);
    $writer->beginTransaction();
    $writer->selectOne('select pg_current_xact_id()');
    $reader->beginTransaction();
    $reader->statement('set transaction isolation level repeatable read');
    $reader->selectOne('select count(*) from events');
    usleep(300_000);

    $open = app(PostgresProbe::class)->openTransactions();
    $result = new OldestTransactionCheck(app(PostgresProbe::class), xidLimitMilliseconds: 250, snapshotLimitMilliseconds: 250)->run();

    expect($open->oldestXid)->toBeInstanceOf(HeldTransaction::class)
        ->and($open->oldestXid?->milliseconds)->toBeGreaterThanOrEqual(300)
        ->and($open->oldestSnapshot)->toBeInstanceOf(HeldTransaction::class)
        ->and($open->oldestSnapshot?->milliseconds)->toBeGreaterThanOrEqual(300)
        ->and($result->status)->toBe(CheckStatus::Fail)
        ->and($result->blocking)->toBeFalse()
        ->and($result->code)->toBe(OldestTransactionCheck::CODE_HORIZON)
        ->and($result->cause)->toContain('has held a transaction id for up to ', 'above the limit of 250 ms.', 'has held a snapshot for up to ')
        ->and(DoctorExitCode::for([$result]))->toBe(DoctorExitCode::NotReady);

    $writer->rollBack();
    $reader->rollBack();
});

it('counts a superuser\'s transaction that holds a transaction id without an age', function (): void {
    $superuser = ScratchRoles::superuser();
    $superuser->beginTransaction();
    $superuser->selectOne('select pg_current_xact_id()');

    $open = app(PostgresProbe::class)->openTransactions();
    $result = new OldestTransactionCheck(app(PostgresProbe::class))->run();

    expect($open->unmeasured)->toBeGreaterThanOrEqual(1)
        ->and($open->unmeasuredRoles)->toContain($superuser->getConfig('username'))
        ->and($result->explanation)->toContain('the app role cannot see for how long.');

    $superuser->rollBack();
});

it('reads the oldest unhandled event of each subscription past its cursor, above the horizon too', function (): void {
    $clock = horizonEventClock();
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    $events = new CommittedEvents($clock);
    $holder = app(IndependentConnections::class)->open(1)[0];

    expect(horizonEventProbe()->lag())->toEqual([
        new SubscriptionLag(new SubscriptionName('test.counters'), Lane::Critical),
        new SubscriptionLag(new SubscriptionName('test.other'), Lane::Standard),
    ]);

    // A transaction that began before the events holds the horizon, so no runner reads them yet.
    $holder->beginTransaction();
    $holder->selectOne('select pg_current_xact_id()');
    [$first] = $events->write(EventStream::Interactive, [CounterRaised::of('counter-1', 1)]);
    $clock->advance(new DateInterval('PT1S'));
    $events->write(EventStream::Bulk, [CounterRaised::of('counter-2', 1)]);

    $lag = horizonEventProbe()->lag();

    expect($lag[0]->oldestUnhandled?->format('Y-m-d\TH:i:s.u\Z'))->toBe('2026-06-02T08:00:00.000000Z')
        ->and($lag[0]->stream)->toBe(EventStream::Interactive)
        ->and($lag[1]->oldestUnhandled)->toBeNull();

    $holder->rollBack();

    // Past the cursor of the interactive stream, the bulk stream's event is the oldest.
    new PostgresSubscriptionLog(app('db'), $clock)->advance(new SubscriptionName('test.counters'), EventStream::Interactive, $first);
    $lag = horizonEventProbe()->lag();

    expect($lag[0]->oldestUnhandled?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-06-02T08:00:01Z')
        ->and($lag[0]->stream)->toBe(EventStream::Bulk);

    $clock->advance(new DateInterval('PT1S'));
    $result = new EventLagCheck(horizonEventProbe(), $clock)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->cause)->toBe('At 2026-06-02T08:00:02.000Z: test.counters (lane critical, target 500 ms) has not handled an event of the bulk stream from 2026-06-02T08:00:01.000Z, 1000 ms old.');
});

it('counts the parked aggregates per subscription, leaving out the released ones', function (): void {
    $db = DB::connection();
    $insert = "insert into event_parked_aggregates (subscription, aggregate_type, aggregate_id, stream, xid, event_id, attempts, parked_at, released_at) values (?, 'counter', ?, 'interactive', '10', 3, 5, now(), ?)";

    expect(horizonEventProbe()->parked())->toBe([])
        ->and(new ParkedAggregatesCheck(horizonEventProbe())->run()->status)->toBe(CheckStatus::Pass);

    $db->insert($insert, ['test.counters', 'counter-1', null]);
    $db->insert($insert, ['test.counters', 'counter-2', null]);
    $db->insert($insert, ['test.counters', 'counter-3', '2026-06-02T08:00:00Z']);
    $db->insert($insert, ['test.archive', 'counter-1', null]);

    expect(horizonEventProbe()->parked())->toEqual([
        new ParkedCount(new SubscriptionName('test.archive'), 1),
        new ParkedCount(new SubscriptionName('test.counters'), 2),
    ])->and(new ParkedAggregatesCheck(horizonEventProbe())->run()->cause)->toBe('test.archive has 1 parked, test.counters has 2 parked.');
});

it('fails the event log probe as a violation without a registry cache', function (): void {
    $probe = new ConnectionEventLogProbe(app(DoctorConnection::class), new FakeRegistryCache);

    expect(fn (): array => $probe->lag())->toThrow(ProbeFailed::class, 'The registry cache cannot be read: ');
});

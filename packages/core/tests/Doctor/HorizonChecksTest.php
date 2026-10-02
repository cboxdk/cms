<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Domain\Checks\EventLagCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\IdleInTransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OldestTransactionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ParkedAggregatesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\Dto\HeldTransaction;
use Cbox\Cms\Core\Doctor\Domain\Dto\OpenTransactions;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeEventLogProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;

/*
 * The checks of the event log and the horizon (PRD 4.2, 7.4, 7.8, 7.12, GUARDRAILS 5) on their
 * own, with fake probes: events.lag, events.parked, postgres.oldest_xact and
 * postgres.idle_in_transaction_timeout.
 */

function horizonClock(): FakeClock
{
    return new FakeClock(new DateTimeImmutable('2026-05-04T10:00:00Z'));
}

function lagOf(string $name, Lane $lane, ?string $occurred, EventStream $stream = EventStream::Interactive): SubscriptionLag
{
    return new SubscriptionLag(new SubscriptionName($name), $lane, $occurred === null ? null : new DateTimeImmutable($occurred), $occurred === null ? null : $stream);
}

it('holds every lane to its lag target and never fails the background lane', function (Lane $lane, ?int $target): void {
    expect(EventLagCheck::targetMilliseconds($lane))->toBe($target);

    $at = static fn (int $milliseconds): string => horizonClock()->now()->modify(sprintf('-%d milliseconds', $milliseconds))->format('Y-m-d\TH:i:s.vP');
    $within = new EventLagCheck(new FakeEventLogProbe([lagOf('fixture.watch', $lane, $at($target ?? 86_400_000))]), horizonClock())->run();
    $beyond = new EventLagCheck(new FakeEventLogProbe([lagOf('fixture.watch', $lane, $at(($target ?? 86_400_000) + 1))]), horizonClock())->run();

    expect($within->status)->toBe(CheckStatus::Pass)
        ->and($beyond->status)->toBe($target === null ? CheckStatus::Pass : CheckStatus::Fail);
})->with([
    'critical' => [Lane::Critical, 500],
    'standard' => [Lane::Standard, 60_000],
    'external' => [Lane::External, 300_000],
    'revalidate' => [Lane::Revalidate, 2_000],
    'background' => [Lane::Background, null],
]);

it('names every lane with its oldest unhandled event when all are within their targets', function (): void {
    $result = new EventLagCheck(new FakeEventLogProbe([
        lagOf('fixture.purge', Lane::Critical, '2026-05-04T09:59:59.900Z'),
        lagOf('fixture.index', Lane::Standard, null),
        lagOf('fixture.feed', Lane::Standard, '2026-05-04T09:59:30Z', EventStream::Bulk),
        lagOf('fixture.analytics', Lane::Background, '2026-05-01T00:00:00Z'),
    ]), horizonClock())->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toBe('Every lane is within its lag target: critical (1 subscription, target 500 ms, oldest unhandled event 100 ms old), standard (2 subscriptions, target 60000 ms, oldest unhandled event 30000 ms old), background (1 subscription, best effort, oldest unhandled event 295200000 ms old).');
});

it('fails with the subscriptions behind their lane, each with its stream, time and age', function (): void {
    $result = new EventLagCheck(new FakeEventLogProbe([
        lagOf('fixture.purge', Lane::Critical, '2026-05-04T09:59:58Z'),
        lagOf('fixture.hook', Lane::Revalidate, '2026-05-04T09:59:57.5Z', EventStream::Bulk),
        lagOf('fixture.index', Lane::Standard, '2026-05-04T09:59:58Z'),
    ]), horizonClock())->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(EventLagCheck::CODE)
        ->and($result->explanation)->toStartWith('Subscriptions of the lanes critical, revalidate are further behind than the lag target')
        ->and($result->cause)->toBe('At 2026-05-04T10:00:00.000Z: fixture.purge (lane critical, target 500 ms) has not handled an event of the interactive stream from 2026-05-04T09:59:58.000Z, 2000 ms old; fixture.hook (lane revalidate, target 2000 ms) has not handled an event of the bulk stream from 2026-05-04T09:59:57.500Z, 2500 ms old.')
        ->and($result->fix)->toContain('php artisan cms:events:run --lane=<lane> (critical, revalidate)');
});

it('counts an event from a clock ahead of the doctor\'s as 0 ms old', function (): void {
    $result = new EventLagCheck(new FakeEventLogProbe([lagOf('fixture.purge', Lane::Critical, '2026-05-04T10:00:03Z')]), horizonClock())->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->explanation)->toContain('oldest unhandled event 0 ms old');
});

it('passes without subscriptions, and fails with the probe\'s kind when the event log cannot be read', function (): void {
    expect(new EventLagCheck(new FakeEventLogProbe, horizonClock())->run()->explanation)->toBe('No subscriptions are registered, so no lane has events to handle.');

    $probe = new FakeEventLogProbe(failure: ProbeFailed::unavailable('The server closed the connection.'));

    foreach ([new EventLagCheck($probe, horizonClock()), new ParkedAggregatesCheck($probe)] as $check) {
        $result = $check->run();

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->blocking)->toBeFalse()
            ->and($result->failure)->toBe(FailureKind::Unavailable)
            ->and($result->code)->toBe(EventLagCheck::CODE_UNREADABLE)
            ->and($result->cause)->toBe('The server closed the connection.');
    }
});

it('fails events.parked with the count per subscription', function (): void {
    $one = new ParkedAggregatesCheck(new FakeEventLogProbe(parkedCounts: [new ParkedCount(new SubscriptionName('fixture.purge'), 1)]))->run();
    $many = new ParkedAggregatesCheck(new FakeEventLogProbe(parkedCounts: [
        new ParkedCount(new SubscriptionName('fixture.index'), 2),
        new ParkedCount(new SubscriptionName('fixture.purge'), 1),
    ]))->run();

    expect(new ParkedAggregatesCheck(new FakeEventLogProbe)->run()->explanation)->toBe('No subscription has parked an aggregate.')
        ->and($one->code)->toBe(ParkedAggregatesCheck::CODE)
        ->and($one->blocking)->toBeFalse()
        ->and($one->explanation)->toBe('1 aggregate is parked: its event failed as often as the runner tries, and its later events wait until it is released.')
        ->and($one->cause)->toBe('fixture.purge has 1 parked.')
        ->and($many->explanation)->toBe('3 aggregates are parked: their events failed as often as the runner tries, and their later events wait until they are released.')
        ->and($many->cause)->toBe('fixture.index has 2 parked, fixture.purge has 1 parked.')
        ->and($many->fix)->toContain('cms:events:parked', 'cms:events:release');
});

it('passes open transactions within the limits and names the sessions it cannot measure', function (): void {
    $probe = new FakePostgresProbe;

    expect(new OldestTransactionCheck($probe)->run()->explanation)->toBe('Within the limits of 5000 ms for a transaction id and 5000 ms for a snapshot: no transaction the app role can see holds a transaction id, and none holds a snapshot in this database.');

    $probe->openTransactions = new OpenTransactions(new HeldTransaction(81, 'cms_app', 1200), new HeldTransaction(82, 'cms_app', 1300), 2, ['cms_owner', 'postgres']);
    $result = new OldestTransactionCheck($probe)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toBe('Within the limits of 5000 ms for a transaction id and 5000 ms for a snapshot: the oldest transaction that holds a transaction id has run for 1200 ms (session pid 81 of the role cms_app), and the oldest that holds a snapshot in this database for 1300 ms (session pid 82 of the role cms_app). 2 sessions of the roles cms_owner, postgres hold a transaction id or a snapshot; the app role cannot see for how long.');

    $probe->openTransactions = new OpenTransactions(null, null, 1, ['cms_owner']);

    expect(new OldestTransactionCheck($probe)->run()->explanation)->toEndWith(' 1 session of the role cms_owner holds a transaction id or a snapshot; the app role cannot see for how long.');
});

it('fails an old transaction id as the held horizon and an old snapshot alone as held vacuum', function (): void {
    $probe = new FakePostgresProbe;
    $probe->openTransactions = new OpenTransactions(new HeldTransaction(81, 'cms_app', 5001), new HeldTransaction(82, 'cms_app', 7000));
    $both = new OldestTransactionCheck($probe)->run();

    $probe->openTransactions = new OpenTransactions(new HeldTransaction(81, 'cms_app', 100), new HeldTransaction(82, 'cms_app', 7000));
    $snapshot = new OldestTransactionCheck($probe)->run();

    $probe->openTransactions = new OpenTransactions(new HeldTransaction(81, 'cms_app', 100), new HeldTransaction(81, 'cms_app', 100));
    $limited = new OldestTransactionCheck($probe, xidLimitMilliseconds: 50, snapshotLimitMilliseconds: 1000)->run();

    expect($both->code)->toBe(OldestTransactionCheck::CODE_HORIZON)
        ->and($both->failure)->toBe(FailureKind::Violation)
        ->and($both->cause)->toBe('The session pid 81 of the role cms_app has held a transaction id for up to 5001 ms, above the limit of 5000 ms. The session pid 82 of the role cms_app has held a snapshot for up to 7000 ms, above the limit of 5000 ms.')
        ->and($both->fix)->toContain('pg_terminate_backend(<pid>)')
        ->and($snapshot->code)->toBe(OldestTransactionCheck::CODE_SNAPSHOT)
        ->and($snapshot->explanation)->toStartWith('A snapshot holds back vacuum')
        ->and($limited->code)->toBe(OldestTransactionCheck::CODE_HORIZON)
        ->and($limited->cause)->toBe('The session pid 81 of the role cms_app has held a transaction id for up to 100 ms, above the limit of 50 ms.');

    $probe->queryFailure = ProbeFailed::unavailable('The server went away.');
    $failed = new OldestTransactionCheck($probe)->run();

    expect($failed->code)->toBe(PostgresQueryFailure::CODE)
        ->and($failed->blocking)->toBeFalse()
        ->and($failed->failure)->toBe(FailureKind::Unavailable);
});

it('passes an idle_in_transaction_session_timeout above zero that comes from the role', function (int $milliseconds, SettingSource $source, bool $passes, string $cause): void {
    $postgres = new FakePostgresProbe;
    $postgres->idleInTransactionTimeoutMs = $milliseconds;
    $postgres->idleInTransactionTimeoutSource = $source;
    $result = new IdleInTransactionTimeoutCheck($postgres)->run();

    expect($result->passed())->toBe($passes)
        ->and($result->blocking)->toBeTrue();

    if (! $passes) {
        expect($result->code)->toBe(IdleInTransactionTimeoutCheck::CODE)
            ->and($result->failure)->toBe(FailureKind::Violation)
            ->and($result->cause)->toContain($cause)
            ->and($result->fix)->toContain("ALTER ROLE cms_app SET idle_in_transaction_session_timeout = '5s'");
    }
})->with([
    'set on the role' => [5000, SettingSource::User, true, ''],
    'one millisecond on the role' => [1, SettingSource::User, true, ''],
    'set on the role in the database' => [2000, SettingSource::DatabaseUser, true, ''],
    'reset on the role' => [0, SettingSource::Default, false, 'is 0 (off) for the role cms_app; Postgres took the value from "default"'],
    'set for the whole server' => [5000, SettingSource::ConfigurationFile, false, 'took it from "configuration file", not from the role'],
    'set by the connection' => [5000, SettingSource::Client, false, 'took it from "client"'],
]);

it('gives each new code a catalog entry with the exit code of its check', function (): void {
    expect(ErrorCode::from(EventLagCheck::CODE)->entry()->exit->value)->toBe(79)
        ->and(ErrorCode::from(EventLagCheck::CODE_UNREADABLE)->entry()->exit->value)->toBe(79)
        ->and(ErrorCode::from(ParkedAggregatesCheck::CODE)->entry()->exit->value)->toBe(79)
        ->and(ErrorCode::from(OldestTransactionCheck::CODE_HORIZON)->entry()->exit->value)->toBe(79)
        ->and(ErrorCode::from(OldestTransactionCheck::CODE_SNAPSHOT)->entry()->exit->value)->toBe(79)
        ->and(ErrorCode::from(IdleInTransactionTimeoutCheck::CODE)->entry()->exit->value)->toBe(78);
});

it('fails idle_in_transaction_session_timeout as a blocking check when Postgres cannot be asked', function (): void {
    $postgres = new FakePostgresProbe;
    $postgres->queryFailure = ProbeFailed::unavailable('The server went away.');
    $failed = new IdleInTransactionTimeoutCheck($postgres)->run();

    expect($failed->code)->toBe(PostgresQueryFailure::CODE)
        ->and($failed->blocking)->toBeTrue()
        ->and($failed->failure)->toBe(FailureKind::Unavailable);
});

it('counts no unmeasured session and names no role when the probe gives only the measured ones', function (): void {
    $open = new OpenTransactions(null, null);

    expect([$open->oldestXid, $open->oldestSnapshot, $open->unmeasured, $open->unmeasuredRoles])->toBe([null, null, 0, []]);
});

it('counts no unmeasured session and names no role unless the probe gives them', function (): void {
    $open = new OpenTransactions(null, null);

    expect([$open->unmeasured, $open->unmeasuredRoles])->toBe([0, []]);
});

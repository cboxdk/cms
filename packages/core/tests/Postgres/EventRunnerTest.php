<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Subscriptions\Actions\ReleaseParked;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\Parking;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Core\Tests\Subscriptions\RunnerScratch;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The event runner on Postgres (PRD 7.4 to 7.8): the critical lane's runner, as an active service
 * actor, over the real event log, cursors and parkings, with a subscriber that writes a row per
 * event in the runner's batch on the default connection (GUARDRAILS 9). Transactions that race get
 * connections of their own.
 */

function eventRunnerClock(): FakeClock
{
    return new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00Z'));
}

beforeEach(function (): void {
    app(PartitionFixtures::class)->coverClock(eventRunnerClock(), new DateInterval('P1D'));
    RunnerScratch::create();
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    RunnerScratch::drop();
});

/**
 * The critical lane's runner with the recording subscriber as test.counters, writing its row for
 * each event before it records the call, so a failed call's row rolls back with its batch.
 */
function eventRunner(SubscriberJournal $journal, int $maxAttempts = 3): RunLane
{
    $clock = eventRunnerClock();
    $service = PostgresIdentity::at($clock)->addActor(ActorClass::Service);
    $journal->each(RunnerScratch::write(...));

    return new RunLane(
        new PostgresSubscriptionLog(app('db'), $clock),
        new FakeLaneSubscribers([RecordingSubscriber::bound($journal)]),
        new PostgresActorDirectory(app(DatabaseManager::class)),
        new RunnerSettings($service->id, maxAttempts: $maxAttempts, backoffBaseMs: 1, backoffMaxMs: 4, idleSleepMs: 5),
        new SystemPacing,
    );
}

function eventRunnerRun(RunLane $runner): LaneReport
{
    return $runner->run(new LaneRun(Lane::Critical, untilIdle: true), new StopAfterRounds);
}

/**
 * Runs the lane until the journal has $count calls, for at most 30 seconds: the transaction horizon
 * is the oldest transaction open on the server, so another checkout's suite can hold it back.
 */
function eventRunnerUntil(RunLane $runner, SubscriberJournal $journal, int $count): void
{
    $deadline = microtime(true) + 30;

    do {
        eventRunnerRun($runner);

        if (count($journal->calls()) >= $count) {
            return;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new AssertionFailedError(sprintf('The runner handled %d of %d events in 30 seconds.', count($journal->calls()), $count));
}

function eventRunnerCursor(): EventPosition
{
    return new PostgresSubscriptionLog(app('db'), eventRunnerClock())->cursor(new SubscriptionName('test.counters'), EventStream::Interactive);
}

it('skips no event when transactions commit out of event_id order', function (): void {
    $journal = new SubscriberJournal;
    $runner = eventRunner($journal);
    $events = new CommittedEvents(eventRunnerClock());
    [$first, $second] = app(IndependentConnections::class)->open(2);

    // The first transaction writes the lower event_id and stays open.
    $first->beginTransaction();
    [$early] = $events->writeOpen(EventStream::Interactive, [CounterRaised::of('counter-early', 1)], $first);

    // The second writes a higher event_id and commits first.
    [$late] = $events->write(EventStream::Interactive, [CounterRaised::of('counter-late', 1)], $second);

    expect($early->eventId)->toBeLessThan($late->eventId);

    // A runner that read "event_id above my cursor" would hand the committed event now and move its
    // cursor past the open one. Below the horizon it hands neither and leaves the cursor.
    eventRunnerRun($runner);

    expect($journal->calls())->toBe([])
        ->and(eventRunnerCursor()->equals(EventPosition::start()))->toBeTrue();

    $first->commit();

    eventRunnerUntil($runner, $journal, 2);

    expect($journal->calls())->toBe(['counter-early@1 try 1', 'counter-late@1 try 1'])
        ->and(RunnerScratch::rows())->toBe(['counter-early@1', 'counter-late@1'])
        ->and(eventRunnerCursor()->equals($late))->toBeTrue();

    // Nothing is handled twice.
    eventRunnerRun($runner);

    expect($journal->calls())->toHaveCount(2);
});

it('parks a failing subscriber\'s aggregate after its tries while other aggregates flow, and handles its current version once after release', function (): void {
    $journal = new SubscriberJournal;
    $journal->break('bad');
    $runner = eventRunner($journal, maxAttempts: 3);
    $events = new CommittedEvents(eventRunnerClock());
    $positions = [
        ...$events->write(EventStream::Interactive, [CounterRaised::of('good-a', 1), CounterRaised::of('bad', 1)]),
        ...$events->write(EventStream::Interactive, [CounterRaised::of('bad', 2), CounterRaised::of('good-b', 1)]),
        ...$events->write(EventStream::Interactive, [CounterRaised::of('bad', 3), CounterRaised::of('good-c', 1)]),
    ];
    $stored = $events->belowHorizon(EventStream::Interactive, $positions);

    $report = eventRunnerRun($runner);

    // bad@1 failed three tries and was parked; its later events were parked with it. good-a was
    // handed again after each rolled-back batch, and its row committed once.
    expect(array_values(array_filter($journal->calls(), static fn (string $call): bool => str_starts_with($call, 'bad'))))
        ->toBe(['bad@1 try 1', 'bad@1 try 2', 'bad@1 try 3'])
        ->and(RunnerScratch::rows())->toBe(['good-a@1', 'good-b@1', 'good-c@1'])
        ->and($report->parked)->toEqual([new Parking(new SubscriptionName('test.counters'), AggregateKey::fromString('counter:bad'), 3)])
        ->and($report->handled)->toBe(3)
        ->and(eventRunnerCursor()->equals($stored[5]->position))->toBeTrue();

    $parked = new PostgresSubscriptionLog(app('db'), eventRunnerClock())->parked(new SubscriptionName('test.counters'));

    expect($parked)->toHaveCount(1)
        ->and($parked[0]->aggregate->toString())->toBe('counter:bad')
        ->and($parked[0]->attempts)->toBe(3)
        ->and($parked[0]->position->equals($stored[1]->position))->toBeTrue();

    // The fault is fixed and the aggregate released: the runner hands its current version once.
    $journal->fix('bad');
    $released = new ReleaseParked(
        new PostgresSubscriptionLog(app('db'), eventRunnerClock()),
        new FakeLaneSubscribers([RecordingSubscriber::bound(new SubscriberJournal)]),
    )->release(new ParkedRelease(new SubscriptionName('test.counters'), AggregateKey::fromString('counter:bad')));

    expect($released)->toBeInstanceOf(ParkedAggregate::class);

    $report = eventRunnerRun($runner);
    eventRunnerRun($runner);

    expect(array_values(array_filter($journal->calls(), static fn (string $call): bool => str_starts_with($call, 'bad@2') || str_starts_with($call, 'bad@3') || str_contains($call, 'release'))))
        ->toBe(['bad@3 try 1 release'])
        ->and($report->released)->toBe(1)
        ->and(RunnerScratch::rows())->toBe(['bad@3 release', 'good-a@1', 'good-b@1', 'good-c@1'])
        ->and(new PostgresSubscriptionLog(app('db'), eventRunnerClock())->parked(null))->toBe([])
        ->and(array_map(static fn (Delivery $delivery): bool => $delivery->release, $journal->deliveries()))->toContain(true);
});

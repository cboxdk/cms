<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Access\Adapter\TransactionalAccessContexts;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Addons\Actions\ResolveSubscriberActor;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
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
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Core\Tests\Subscriptions\RunnerScratch;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
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

    return eventRunnerWith([RecordingSubscriber::bound($journal)], $service->id, new ServiceActors, $maxAttempts);
}

/**
 * The critical lane's runner on Postgres with the bindings, as the service actor, and the addons'
 * service actors.
 *
 * @param  list<SubscriberBinding>  $bindings
 */
function eventRunnerWith(array $bindings, ActorId $service, ServiceActors $addonActors, int $maxAttempts = 3): RunLane
{
    $directory = new PostgresActorDirectory(app(DatabaseManager::class));

    return new RunLane(
        new PostgresSubscriptionLog(app('db'), eventRunnerClock()),
        new FakeLaneSubscribers($bindings),
        $directory,
        new RunnerSettings($service, maxAttempts: $maxAttempts, backoffBaseMs: 1, backoffMaxMs: 4, idleSleepMs: 5),
        new SystemPacing,
        new TransactionalAccessContexts(app('db'), new AccessCompiler),
        new ResolveSubscriberActor($addonActors, $directory),
    );
}

/**
 * The actor of the access context on the default connection, the batch's, as row level security
 * reads it; "none" without one.
 */
function eventRunnerContextActor(): string
{
    $row = app('db')->selectOne("select nullif(current_setting('cbox_cms.actor', true), '') as actor");
    $actor = is_object($row) && property_exists($row, 'actor') ? $row->actor : null;

    return is_string($actor) ? $actor : 'none';
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

it('runs an addon\'s subscriber under the addon\'s own service actor and a kernel subscriber under the runner\'s, on the batch\'s connection (invariant 21)', function (): void {
    $identity = PostgresIdentity::at(eventRunnerClock());
    $runnerActor = $identity->addActor(ActorClass::Service);
    $addonActor = $identity->addActor(ActorClass::Service);
    $kernel = new SubscriberJournal;
    $addon = new SubscriberJournal;
    $kernelSeen = [];
    $addonSeen = [];
    $kernel->each(static function () use (&$kernelSeen): void {
        $kernelSeen[] = eventRunnerContextActor();
    });
    $addon->each(static function () use (&$addonSeen): void {
        $addonSeen[] = eventRunnerContextActor();
    });
    $runner = eventRunnerWith(
        [RecordingSubscriber::bound($kernel), RecordingSubscriber::bound($addon, 'fixtureaddon.counters', addon: new AddonNamespace('fixtureaddon'))],
        $runnerActor->id,
        new ServiceActors(['fixtureaddon' => $addonActor->id]),
    );
    new CommittedEvents(eventRunnerClock())->commit(EventStream::Interactive, [CounterRaised::of('counter-a', 1)]);

    eventRunnerUntil($runner, $addon, 1);
    eventRunnerUntil($runner, $kernel, 1);

    expect($addonSeen)->toBe([$addonActor->id->toString()])
        ->and($kernelSeen)->toBe([$runnerActor->id->toString()])
        ->and($addon->deliveries()[0]->actor->equals($addonActor->id))->toBeTrue()
        ->and($kernel->deliveries()[0]->actor->equals($runnerActor->id))->toBeTrue()
        ->and(eventRunnerContextActor())->toBe('none');
});

it('refuses an addon\'s subscriber without an active service actor and never runs it as the runner\'s actor', function (): void {
    $identity = PostgresIdentity::at(eventRunnerClock());
    $runnerActor = $identity->addActor(ActorClass::Service);
    $kernel = new SubscriberJournal;
    $addon = new SubscriberJournal;
    $runner = eventRunnerWith(
        [RecordingSubscriber::bound($kernel), RecordingSubscriber::bound($addon, 'fixtureaddon.counters', addon: new AddonNamespace('fixtureaddon'))],
        $runnerActor->id,
        new ServiceActors,
    );
    new CommittedEvents(eventRunnerClock())->commit(EventStream::Interactive, [CounterRaised::of('counter-a', 1)]);

    eventRunnerUntil($runner, $kernel, 1);
    $report = eventRunnerRun($runner);

    expect($addon->calls())->toBe([])
        ->and($report->refused)->toHaveCount(1)
        ->and($report->refused[0]->code)->toBe('addon_service_actor_unavailable')
        ->and(new PostgresSubscriptionLog(app('db'), eventRunnerClock())->cursor(new SubscriptionName('fixtureaddon.counters'), EventStream::Interactive)->equals(EventPosition::start()))->toBeTrue();
});

/**
 * The ids of the nodes, in order, that the batch's connection reads under its access context.
 *
 * @param  list<StructureNode>  $nodes
 * @return list<string>
 */
function eventRunnerVisibleNodes(array $nodes): array
{
    $ids = array_map(static fn (StructureNode $node): string => $node->id->toString(), $nodes);

    return array_values(array_filter(
        $ids,
        static fn (string $id): bool => app('db')->table('nodes')->where('id', $id)->exists(),
    ));
}

/**
 * Whether the batch's connection changed the node: an update row level security filters out
 * changes nothing.
 */
function eventRunnerTouchesNode(StructureNode $node): bool
{
    return app('db')->table('nodes')->where('id', $node->id->toString())->update(['version' => 1]) === 1;
}

/**
 * Adds a section below the parent on the batch's connection; row level security refuses a row
 * outside the context's regions with SQLSTATE 42501, which fails the batch.
 */
function eventRunnerAddNodeBelow(StructureNode $parent, FakeIdGenerator $ids): string
{
    $id = $ids->next()->value;

    app('db')->table('nodes')->insert([
        'id' => $id,
        'parent_id' => $parent->id->toString(),
        'kind' => 'section',
        'path' => $parent->path->value.'.'.str_replace('-', '', $id),
        'version' => 1,
        'created_at' => '2026-04-01 08:00:00+00',
    ]);

    return $id;
}

it('holds an addon\'s subscriber to its service actor\'s regions: it reads and writes the nodes its grants reach and none of the runner\'s (invariant 21)', function (): void {
    $clock = eventRunnerClock();
    $connections = app(ConnectionResolverInterface::class);
    $structure = new PostgresStructureFixtures($connections, $clock, new FakeIdGenerator(seed: 910, clock: $clock));
    $access = new PostgresAccessFixtures($connections, $clock, new FakeIdGenerator(seed: 920, clock: $clock));
    $north = $structure->site('north', [new Locale('da')]);
    $south = $structure->site('south', [new Locale('da')]);
    $northSection = $structure->node($north->root);
    $southSection = $structure->node($south->root);
    $identity = PostgresIdentity::at($clock);
    $runnerActor = $identity->addActor(ActorClass::Service);
    $addonActor = $identity->addActor(ActorClass::Service);
    $role = $access->role('subscriber', ClassificationAccess::Internal);
    $access->grant($runnerActor->id, $role, $south->root->id);
    $access->grant($addonActor->id, $role, $northSection->id);
    $nodes = [$north->root, $northSection, $south->root, $southSection];
    $ids = new FakeIdGenerator(seed: 930, clock: $clock);
    $kernel = new SubscriberJournal;
    $addon = new SubscriberJournal;
    $outside = new SubscriberJournal;
    $seen = [];
    $added = [];
    $kernel->each(static function () use (&$seen, $nodes, $southSection): void {
        $seen['kernel'] = [eventRunnerVisibleNodes($nodes), eventRunnerTouchesNode($southSection)];
    });
    $addon->each(static function () use (&$seen, &$added, $nodes, $northSection, $southSection, $ids): void {
        $seen['addon'] = [eventRunnerVisibleNodes($nodes), eventRunnerTouchesNode($northSection), eventRunnerTouchesNode($southSection)];
        $added[] = eventRunnerAddNodeBelow($northSection, $ids);
    });
    $outside->each(static function () use (&$seen, &$added, $southSection, $ids): void {
        try {
            $added[] = eventRunnerAddNodeBelow($southSection, $ids);
        } catch (QueryException $refused) {
            $seen['outside'] = (string) $refused->getCode();

            throw $refused;
        }
    });
    $addonNamespace = new AddonNamespace('fixtureaddon');
    $runner = eventRunnerWith(
        [
            RecordingSubscriber::bound($kernel),
            RecordingSubscriber::bound($addon, 'fixtureaddon.counters', addon: $addonNamespace),
            RecordingSubscriber::bound($outside, 'fixtureaddon.outside', addon: $addonNamespace),
        ],
        $runnerActor->id,
        new ServiceActors(['fixtureaddon' => $addonActor->id]),
        maxAttempts: 1,
    );
    new CommittedEvents($clock)->commit(EventStream::Interactive, [CounterRaised::of('counter-a', 1)]);

    eventRunnerUntil($runner, $addon, 1);
    eventRunnerUntil($runner, $kernel, 1);

    $stored = $connections->connection('pgsql_owner')->table('nodes')->whereIn('id', $added)->pluck('parent_id')->all();

    expect($seen['addon'])->toBe([[$northSection->id->toString()], true, false])
        ->and($seen['kernel'])->toBe([[$south->root->id->toString(), $southSection->id->toString()], true])
        ->and($seen['outside'] ?? null)->toBe('42501')
        ->and($stored)->toBe([$northSection->id->toString()])
        ->and(array_map(
            static fn (ParkedAggregate $parked): string => $parked->subscription->value.' '.$parked->aggregate->id->value,
            new PostgresSubscriptionLog(app('db'), $clock)->parked(null),
        ))->toBe(['fixtureaddon.outside counter-a']);
});

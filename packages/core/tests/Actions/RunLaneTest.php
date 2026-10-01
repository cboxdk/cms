<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Actions\ReleaseParked;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\Parking;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\ServiceIdentityRefused;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\CounterReset;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Core\Tests\Subscriptions\LaneWorld;

/*
 * The event runner of a lane (PRD 7.4 to 7.8), called directly with its DTOs and the fake log,
 * pacing and identity (GUARDRAILS 9): it hands the events after each subscription's cursor to the
 * subscriber as the service actor, moves the cursor in the batch's transaction, tries a failed
 * event again with exponential backoff, parks its aggregate after max_attempts tries while the
 * other aggregates flow, and hands a released aggregate once at its current version.
 */

function laneSubscription(): SubscriptionName
{
    return new SubscriptionName('test.counters');
}

function laneAggregate(string $id): AggregateKey
{
    return AggregateKey::fromString('counter:'.$id);
}

function laneRelease(LaneWorld $world, string $id): ParkedAggregate
{
    return new ReleaseParked($world->log, new FakeLaneSubscribers($world->bindings))->release(new ParkedRelease(laneSubscription(), laneAggregate($id)));
}

/**
 * @return list<string> each parked aggregate as "<id> <attempts>[ released]"
 */
function laneParked(LaneWorld $world): array
{
    return array_map(
        static fn (ParkedAggregate $parked): string => $parked->aggregate->id->value.' '.$parked->attempts.($parked->isReleased() ? ' released' : ''),
        $world->log->parked(laneSubscription()),
    );
}

it('hands every event after the cursor in order, once, as the service actor, and moves the cursor', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1', 'b@1');
    $last = $world->raise('a@2');

    $report = $world->untilIdle();

    expect($world->journal->calls())->toBe(['a@1 try 1', 'b@1 try 1', 'a@2 try 1'])
        ->and(array_map(static fn (Delivery $delivery): string => $delivery->actor->toString(), $world->journal->deliveries()))
        ->each->toBe($world->service->id->toString())
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($last[0]->position))->toBeTrue()
        ->and($report->handled)->toBe(3)
        ->and($report->actor->equals($world->service->id))->toBeTrue()
        ->and($report->lane)->toBe(Lane::Critical)
        ->and($report->parked)->toBe([])
        ->and($report->failures)->toBe(0);

    // The cursor committed: a second run hands nothing again.
    $world->untilIdle();

    expect($world->journal->calls())->toHaveCount(3);
});

it('reads each stream after its own cursor', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1');
    [$bulk] = $world->log->record(EventStream::Bulk, [CounterRaised::of('import', 1)]);

    $world->untilIdle();

    expect($world->journal->calls())->toBe(['a@1 try 1', 'import@1 try 1'])
        ->and($world->log->cursor(laneSubscription(), EventStream::Bulk)->equals($bulk->position))->toBeTrue();
});

it('passes the events of a type the subscription does not receive and moves the cursor past them', function (): void {
    $world = new LaneWorld;
    [$reset] = $world->log->record(EventStream::Interactive, [CounterReset::of('a', 1)]);

    $report = $world->untilIdle();

    expect($world->journal->calls())->toBe([])
        ->and($report->passed)->toBe(1)
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($reset->position))->toBeTrue();
});

it('tries a failing event again with exponential backoff and parks its aggregate after max_attempts tries, while the other aggregates flow', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->raise('bad@1');
    $world->raise('good@1');
    $world->raise('bad@2');
    $last = $world->raise('good@2');

    $report = $world->untilIdle($world->settings(maxAttempts: 3));

    expect($world->journal->calls())->toBe(['bad@1 try 1', 'bad@1 try 2', 'bad@1 try 3', 'good@1 try 1', 'good@2 try 1'])
        ->and($world->pacing->sleeps())->toBe([100, 200])
        ->and(laneParked($world))->toBe(['bad 3'])
        ->and($report->parked)->toEqual([new Parking(laneSubscription(), laneAggregate('bad'), 3)])
        ->and($report->failures)->toBe(3)
        ->and($report->handled)->toBe(2)
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($last[0]->position))->toBeTrue();
});

it('parks the later events of a parked aggregate with it', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->raise('bad@1');
    $world->untilIdle($world->settings(maxAttempts: 1));
    $world->journal->fix('bad');
    $world->raise('bad@2', 'good@1');

    $report = $world->untilIdle($world->settings(maxAttempts: 1));

    expect($world->journal->calls())->toBe(['bad@1 try 1', 'good@1 try 1'])
        ->and($report->passed)->toBe(1)
        ->and(laneParked($world))->toBe(['bad 1']);
});

it('rolls back a batch whose subscriber fails, hands the events before the failed one again at once, and the failed one after its backoff', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    [$good, $bad] = $world->raise('good@1', 'bad@1');
    $cursors = [];
    $world->journal->each(static function (StoredEvent $event) use ($world, &$cursors): void {
        $cursors[] = $world->log->cursor(laneSubscription(), EventStream::Interactive);
    });

    $world->untilIdle($world->settings(maxAttempts: 2));

    expect($world->journal->calls())->toBe(['good@1 try 1', 'bad@1 try 1', 'good@1 try 1', 'bad@1 try 2'])
        ->and($world->pacing->sleeps())->toBe([100])
        // The first batch rolled back, so good@1 was handed again from the start; the failed
        // event's retry came in a batch of its own after good@1 committed.
        ->and(array_map(static fn (EventPosition $cursor): int => $cursor->eventId, $cursors))->toBe([0, 0, 0, $good->position->eventId])
        ->and(laneParked($world))->toBe(['bad 2'])
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($bad->position))->toBeTrue();
});

it('hands a released aggregate once, at its current version, and removes its parking', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->raise('bad@1');
    $world->raise('bad@2', 'good@1');
    $world->raise('bad@3');
    $world->untilIdle($world->settings(maxAttempts: 2));

    expect(laneParked($world))->toBe(['bad 2']);

    $world->journal->fix('bad');
    $released = laneRelease($world, 'bad');

    expect($released->isReleased())->toBeTrue()
        ->and(laneParked($world))->toBe(['bad 2 released']);

    $report = $world->untilIdle($world->settings(maxAttempts: 2));

    expect(array_slice($world->journal->calls(), -1))->toBe(['bad@3 try 1 release'])
        ->and(array_filter($world->journal->calls(), static fn (string $call): bool => str_starts_with($call, 'bad@3')))->toHaveCount(1)
        ->and($report->released)->toBe(1)
        ->and(laneParked($world))->toBe([]);

    $world->untilIdle();

    expect(array_filter($world->journal->calls(), static fn (string $call): bool => str_starts_with($call, 'bad@3')))->toHaveCount(1);
});

it('hands a later event of a released aggregate in its turn after the release', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->raise('bad@1');
    $world->untilIdle($world->settings(maxAttempts: 1));
    $world->journal->fix('bad');
    laneRelease($world, 'bad');

    $world->untilIdle($world->settings(maxAttempts: 1));
    $world->raise('bad@2');
    $world->untilIdle($world->settings(maxAttempts: 1));

    expect($world->journal->calls())->toBe(['bad@1 try 1', 'bad@1 try 1 release', 'bad@2 try 1']);
});

it('parks a released aggregate again when its release fails max_attempts tries', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->raise('bad@1');
    $world->untilIdle($world->settings(maxAttempts: 2));
    laneRelease($world, 'bad');

    $report = $world->untilIdle($world->settings(maxAttempts: 2));

    expect(array_slice($world->journal->calls(), 2))->toBe(['bad@1 try 1 release', 'bad@1 try 2 release'])
        ->and(laneParked($world))->toBe(['bad 4'])
        ->and($report->parked)->toEqual([new Parking(laneSubscription(), laneAggregate('bad'), 4)]);
});

it('unparks and reports a released aggregate that has no event of the subscription\'s types left', function (): void {
    $world = new LaneWorld;
    [$reset] = $world->log->record(EventStream::Interactive, [CounterReset::of('ghost', 1)]);
    $world->log->transaction(laneSubscription(), AccessContext::anonymous(), static function () use ($world, $reset): BatchProgress {
        $world->log->advance(laneSubscription(), EventStream::Interactive, $reset->position);
        $world->log->park(laneSubscription(), $reset, 5);

        return new BatchProgress;
    });
    laneRelease($world, 'ghost');

    $report = $world->untilIdle();

    expect($world->journal->calls())->toBe([])
        ->and($report->releasedWithoutEvent)->toEqual([laneAggregate('ghost')])
        ->and(laneParked($world))->toBe([]);
});

it('ends a batch once it has run its budget and commits the cursor, so the next batch goes on from there', function (): void {
    $world = new LaneWorld;
    [$a1, $a2, $a3, $a4] = $world->raise('a@1', 'a@2', 'a@3', 'a@4');
    $cursors = [];
    $world->journal->each(static function () use ($world, &$cursors): void {
        $cursors[] = $world->log->cursor(laneSubscription(), EventStream::Interactive)->eventId;
        $world->pacing->advance(30);
    });

    $world->untilIdle($world->settings(batchBudgetMs: 50));

    expect($world->journal->calls())->toBe(['a@1 try 1', 'a@2 try 1', 'a@3 try 1', 'a@4 try 1'])
        ->and($cursors)->toBe([0, 0, $a2->position->eventId, $a2->position->eventId])
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($a4->position))->toBeTrue()
        ->and($a1->position->eventId)->toBeLessThan($a3->position->eventId);
});

it('reads at most batch_size events per batch', function (): void {
    $world = new LaneWorld;
    [, $second, , $fourth, $fifth] = $world->raise('a@1', 'a@2', 'a@3', 'a@4', 'a@5');
    $cursors = [];
    $world->journal->each(static function () use ($world, &$cursors): void {
        $cursors[] = $world->log->cursor(laneSubscription(), EventStream::Interactive)->eventId;
    });

    $world->untilIdle($world->settings(batchSize: 2));

    expect($cursors)->toBe([0, 0, $second->position->eventId, $second->position->eventId, $fourth->position->eventId])
        ->and($world->log->cursor(laneSubscription(), EventStream::Interactive)->equals($fifth->position))->toBeTrue();
});

it('passes a subscription whose lock another runner holds', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1');
    $world->log->hold(laneSubscription());

    $report = $world->untilIdle();

    expect($world->journal->calls())->toBe([])
        ->and($report->busy)->toBeGreaterThan(0);

    $world->log->free(laneSubscription());
    $world->untilIdle();

    expect($world->journal->calls())->toBe(['a@1 try 1']);
});

it('runs only the subscriptions of its lane, each after its own cursor', function (): void {
    $world = new LaneWorld;
    $other = new SubscriberJournal;
    $standard = new SubscriberJournal;
    $world->bindings[] = RecordingSubscriber::bound($other, 'test.others');
    $world->bindings[] = RecordingSubscriber::bound($standard, 'test.standard', Lane::Standard);
    $world->journal->break('bad');
    $world->raise('bad@1', 'good@1');

    $world->untilIdle($world->settings(maxAttempts: 1));

    expect($world->journal->calls())->toBe(['bad@1 try 1', 'good@1 try 1'])
        ->and($other->calls())->toBe(['bad@1 try 1', 'good@1 try 1'])
        ->and($standard->calls())->toBe([])
        ->and(laneParked($world))->toBe(['bad 1'])
        ->and($world->log->parked(new SubscriptionName('test.others')))->toBe([]);
});

it('runs until it is asked to stop, waiting idle_sleep_ms after a round with nothing to do', function (): void {
    $world = new LaneWorld;
    $stop = new StopAfterRounds(2);

    $world->runner()->run(new LaneRun(Lane::Critical), $stop);

    expect($world->pacing->sleeps())->toBe([200, 200])
        ->and($stop->asked())->toBe(3);
});

it('refuses to run without a service actor it can run as', function (?string $configured, ActorClass $class, ActorState $state, string $code): void {
    $world = new LaneWorld;
    $actor = $world->identity->addActor($class, $state);
    $id = match ($configured) {
        null => null,
        'unknown' => ActorId::fromString('01960000-0000-7000-8000-0000000000ff'),
        default => $actor->id,
    };
    $world->raise('a@1');

    $refusal = null;

    try {
        $world->untilIdle(new RunnerSettings($id));
    } catch (ServiceIdentityRefused $refused) {
        $refusal = $refused->errorCode;
    }

    expect($refusal)->toBe($code);

    expect($world->journal->calls())->toBe([]);
})->with([
    'none configured' => [null, ActorClass::Service, ActorState::Active, 'subscription_identity_invalid'],
    'unknown' => ['unknown', ActorClass::Service, ActorState::Active, 'subscription_identity_invalid'],
    'a staff actor' => ['actor', ActorClass::Staff, ActorState::Active, 'subscription_identity_invalid'],
    'a deactivated service actor' => ['actor', ActorClass::Service, ActorState::Deactivated, 'actor_not_active'],
    'a pending service actor' => ['actor', ActorClass::Service, ActorState::Pending, 'actor_not_active'],
]);

it('stops within a round once its service actor is deactivated', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1');
    $world->journal->each(static function () use ($world): void {
        $world->identity->changeState($world->service->id, ActorState::Deactivated);
    });
    $world->raise('a@2');

    expect(fn (): LaneReport => $world->runner()->run(new LaneRun(Lane::Critical), new StopAfterRounds))
        ->toThrow(ServiceIdentityRefused::class, 'is deactivated, not active');

    expect($world->journal->calls())->toBe(['a@1 try 1', 'a@2 try 1']);
});

/**
 * The actor of the access context the fake log's open batch runs under, as "<actor>".
 */
function laneContextActor(LaneWorld $world): string
{
    $principal = $world->log->context()?->principal;

    return $principal instanceof ActorPrincipal ? $principal->actor->toString() : 'none';
}

it('runs each subscription under its own actor: an addon\'s as the addon\'s service actor, the others as the runner\'s', function (): void {
    $world = new LaneWorld;
    $reviews = $world->identity->addActor(ActorClass::Service);
    $world->addonActors = ['reviews' => $reviews->id];
    $addonJournal = new SubscriberJournal;
    $world->bindings[] = RecordingSubscriber::bound($addonJournal, 'reviews.counters', addon: new AddonNamespace('reviews'));
    $kernelSeen = [];
    $addonSeen = [];
    $world->journal->each(static function () use ($world, &$kernelSeen): void {
        $kernelSeen[] = laneContextActor($world);
    });
    $addonJournal->each(static function () use ($world, &$addonSeen): void {
        $addonSeen[] = laneContextActor($world);
    });
    $world->raise('a@1', 'b@1');

    $report = $world->untilIdle();

    expect($world->journal->calls())->toBe(['a@1 try 1', 'b@1 try 1'])
        ->and($addonJournal->calls())->toBe(['a@1 try 1', 'b@1 try 1'])
        ->and($kernelSeen)->toBe([$world->service->id->toString(), $world->service->id->toString()])
        ->and($addonSeen)->toBe([$reviews->id->toString(), $reviews->id->toString()])
        ->and(array_map(static fn (Delivery $delivery): string => $delivery->actor->toString(), $addonJournal->deliveries()))
        ->each->toBe($reviews->id->toString())
        ->and($report->actor->equals($world->service->id))->toBeTrue()
        ->and($report->refused)->toBe([]);

    // The contexts are compiled once per actor and round, each for a service principal.
    $asked = array_map(static fn (Principal $principal): string => $principal instanceof ActorPrincipal ? $principal->actor->toString().' '.$principal->issuerKind->value : 'anonymous', $world->contexts->asked);

    expect(array_values(array_unique($asked)))->toBe([$world->service->id->toString().' service', $reviews->id->toString().' service']);
});

it('refuses an addon\'s subscription whose service actor is unavailable, hands it nothing and keeps its cursor, while the others run', function (?string $configured, string $reason): void {
    $world = new LaneWorld;
    $other = $world->identity->addActor(ActorClass::Service);
    $world->addonActors = match ($configured) {
        null => [],
        'unknown' => ['reviews' => ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000a001')],
        'staff' => ['reviews' => $world->identity->addActor(ActorClass::Staff)->id],
        default => ['reviews' => $world->identity->addActor(ActorClass::Service, ActorState::Deactivated)->id],
    };
    $world->addonActors['glossary'] = $other->id;
    $addonJournal = new SubscriberJournal;
    $world->bindings[] = RecordingSubscriber::bound($addonJournal, 'reviews.counters', addon: new AddonNamespace('reviews'));
    $world->raise('a@1');

    $report = $world->untilIdle();

    expect($addonJournal->calls())->toBe([])
        ->and($world->log->cursor(new SubscriptionName('reviews.counters'), EventStream::Interactive)->equals(EventPosition::start()))->toBeTrue()
        ->and($world->journal->calls())->toBe(['a@1 try 1'])
        ->and($report->refused)->toHaveCount(1)
        ->and($report->refused[0]->subscription->value)->toBe('reviews.counters')
        ->and($report->refused[0]->code)->toBe('addon_service_actor_unavailable')
        ->and($report->refused[0]->reason)->toContain($reason);
})->with([
    'not configured' => [null, 'no service actor is configured for the addon'],
    'unknown' => ['unknown', 'no actor has the configured service actor id'],
    'not a service actor' => ['staff', 'is a staff actor'],
    'not active' => ['deactivated', 'in the state deactivated'],
]);

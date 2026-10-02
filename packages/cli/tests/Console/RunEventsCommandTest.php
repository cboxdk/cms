<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Actions\ReleaseParked;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\CounterReset;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Core\Tests\Subscriptions\LaneWorld;
use Illuminate\Console\Signals;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

/*
 * cms:events:run with RunLane on the fakes of the runner's action tests: the lines it prints for
 * parked aggregates, refused subscriptions and aggregates released without an event, the summary,
 * what it logs with which context, how it exits for invalid options and a service actor it cannot
 * run as, and that SIGTERM and SIGINT end the run after the batch in progress. EventsCommandsTest
 * in the Postgres suite runs it on real Postgres.
 */

/**
 * @param  array<array-key, mixed>  $options
 * @return array{int, list<string>}
 */
function runEventsCli(array $options = ['--until-idle' => true]): array
{
    $status = Artisan::call('cms:events:run', $options);

    return [$status, array_values(array_filter(array_map(rtrim(...), explode("\n", Artisan::output())), static fn (string $line): bool => $line !== ''))];
}

function runEventsLogger(): RecordingLogger
{
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    return $logger;
}

/**
 * Runs the command with signals available, as outside a test, with a handler of the test's own
 * on the signal, so a signal the command does not trap never ends the test process.
 *
 * @return array{int, list<string>, list<int>}
 */
function runEventsWithSignal(LaneWorld $world, int $signal): array
{
    $outside = [];
    pcntl_signal($signal, static function (int $received) use (&$outside): void {
        $outside[] = $received;
    });
    Signals::resolveAvailabilityUsing(static fn (): bool => true);
    $world->journal->each(static function () use ($signal): void {
        posix_kill(posix_getpid(), $signal);
    });
    app()->instance(RunLane::class, $world->runner($world->settings(batchSize: 1)));

    try {
        [$status, $lines] = runEventsCli();
    } finally {
        Signals::resolveAvailabilityUsing(static fn (): bool => false);
        pcntl_signal($signal, SIG_DFL);
    }

    return [$status, $lines, $outside];
}

it('prints and logs the parked aggregates, the refused subscriptions, the aggregates released without an event and the summary', function (): void {
    $world = new LaneWorld;
    $world->journal->break('bad');
    $world->bindings[] = RecordingSubscriber::bound(new SubscriberJournal, 'reviews.counters', addon: new AddonNamespace('reviews'));
    $subscription = new SubscriptionName('test.counters');
    [$reset] = $world->log->record(EventStream::Interactive, [CounterReset::of('ghost', 1)]);
    $world->log->transaction($subscription, AccessContext::anonymous(), static function () use ($world, $subscription, $reset): BatchProgress {
        $world->log->advance($subscription, EventStream::Interactive, $reset->position);
        $world->log->park($subscription, $reset, 5);

        return new BatchProgress;
    });
    new ReleaseParked($world->log, new FakeLaneSubscribers($world->bindings))->release(new ParkedRelease($subscription, AggregateKey::fromString('counter:ghost')));
    $world->raise('bad@1');
    $world->raise('good@1');
    $logger = runEventsLogger();
    app()->instance(RunLane::class, $world->runner($world->settings(maxAttempts: 3)));

    [$status, $lines] = runEventsCli();

    $actor = $world->service->id->toString();
    $reason = 'The subscriber Cbox\\Cms\\Core\\Tests\\Subscriptions\\Fixtures\\RecordingSubscriber of addon "reviews" cannot run: no service actor is configured for the addon. Set cbox-cms.addons.service_actors.reviews to the id of the service actor created when its capabilities were approved.';

    expect($status)->toBe(0)
        ->and($lines)->toBe([
            'parked test.counters counter:bad after 3 tries',
            'refused reviews.counters: '.$reason,
            'released counter:ghost without an event to hand',
            'Lane critical ran as '.$actor.': 1 handled, 0 passed, 3 failed tries, 1 parked, 0 released, 14 batches, 0 busy.',
        ])
        ->and($logger->records)->toBe([
            ['error', 'The event runner refused a subscription without an actor it may run as.', ['code' => 'addon_service_actor_unavailable', 'lane' => 'critical', 'subscription' => 'reviews.counters']],
            ['info', 'The event runner ran.', [
                'lane' => 'critical',
                'actor' => $actor,
                'handled' => 1,
                'failures' => 3,
                'parked' => ['test.counters counter:bad'],
                'released' => 0,
                'refused' => ['reviews.counters'],
            ]],
        ]);
});

it('prints only the summary and logs empty lists when nothing was parked, refused or released without an event', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1', 'b@1');
    $logger = runEventsLogger();
    app()->instance(RunLane::class, $world->runner());

    [$status, $lines] = runEventsCli();

    $actor = $world->service->id->toString();

    expect($status)->toBe(0)
        ->and($lines)->toBe(['Lane critical ran as '.$actor.': 2 handled, 0 passed, 0 failed tries, 0 parked, 0 released, 6 batches, 0 busy.'])
        ->and($logger->records)->toBe([
            ['info', 'The event runner ran.', ['lane' => 'critical', 'actor' => $actor, 'handled' => 2, 'failures' => 0, 'parked' => [], 'released' => 0, 'refused' => []]],
        ]);
});

it('exits 64 for a lane it does not run, without running the lane', function (): void {
    $world = new LaneWorld;
    $world->raise('a@1');
    $logger = runEventsLogger();
    app()->instance(RunLane::class, $world->runner());

    [$status, $lines] = runEventsCli(['--until-idle' => true, '--lane' => 'bogus']);

    expect($status)->toBe(64)
        ->and($lines)->toBe(['--lane must be one of critical, standard, external, revalidate, background.'])
        ->and($world->journal->calls())->toBe([])
        ->and($logger->records)->toBe([]);
});

it('exits with the catalog\'s code, prints the refusal and logs its code and lane for a service actor it cannot run as', function (string $actor, int $exit, string $code, string $message): void {
    $world = new LaneWorld;
    $world->raise('a@1');
    $id = match ($actor) {
        'not configured' => null,
        'unknown' => ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000a001'),
        default => $world->identity->addActor(ActorClass::Service, ActorState::Deactivated)->id,
    };
    $logger = runEventsLogger();
    app()->instance(RunLane::class, $world->runner(new RunnerSettings($id)));

    [$status, $lines] = runEventsCli();

    expect($status)->toBe($exit)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toContain($message)
        ->and($world->journal->calls())->toBe([])
        ->and($logger->records)->toBe([
            ['error', 'The event runner cannot run as its service actor.', ['code' => $code, 'lane' => 'critical']],
        ]);
})->with([
    'not configured' => ['not configured', 78, 'subscription_identity_invalid', 'The event runner runs its subscribers as a service actor, and cbox-cms.events.runner.service_actor names none. Create a service actor and name its id there.'],
    'unknown' => ['unknown', 78, 'subscription_identity_invalid', 'The service actor 01936f5e-8a2b-7c3d-9e4f-00000000a001 that cbox-cms.events.runner.service_actor names does not exist.'],
    'not active' => ['deactivated', 77, 'actor_not_active', 'is deactivated, not active, so its subscribers do not run (PRD 5.16).'],
]);

it('ends the run after the batch in progress on SIGTERM and on SIGINT', function (int $signal): void {
    $world = new LaneWorld;
    $world->raise('a@1', 'b@1', 'c@1');

    [$status, $lines, $outside] = runEventsWithSignal($world, $signal);

    expect($status)->toBe(0)
        ->and($world->journal->calls())->toBe(['a@1 try 1'])
        ->and($outside)->toBe([$signal])
        ->and($lines)->toBe(['Lane critical ran as '.$world->service->id->toString().': 1 handled, 0 passed, 0 failed tries, 0 parked, 0 released, 3 batches, 0 busy.']);
})->with([
    'SIGTERM' => [SIGTERM],
    'SIGINT' => [SIGINT],
]);

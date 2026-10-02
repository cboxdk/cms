<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Boundary\SubscriptionArguments;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use InvalidArgumentException;

/*
 * The event runner's commands on real Postgres (PRD 7.6 to 7.8): cms:events:run runs the critical
 * lane as the configured service actor, cms:events:parked lists what it parked, and
 * cms:events:release releases an aggregate, with the exit codes of the error catalog. The
 * subscribers are the test's, bound in the container.
 */

function eventsCommandsClock(): FakeClock
{
    return new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00Z'));
}

beforeEach(function (): void {
    app(PartitionFixtures::class)->coverClock(eventsCommandsClock(), new DateInterval('P1D'));
    config()->set('cbox-cms.events.runner.backoff_base_ms', 1);
    config()->set('cbox-cms.events.runner.backoff_max_ms', 2);
    config()->set('cbox-cms.events.runner.idle_sleep_ms', 5);
    config()->set('cbox-cms.events.runner.max_attempts', 2);
});

/**
 * Registers the recording subscriber as test.counters and names an actor of the class and state as
 * the service actor.
 */
function eventsCommandsWorld(ActorClass $class = ActorClass::Service, ActorState $state = ActorState::Active): SubscriberJournal
{
    $journal = new SubscriberJournal;
    app()->instance(LaneSubscribers::class, new FakeLaneSubscribers([RecordingSubscriber::bound($journal)]));
    config()->set('cbox-cms.events.runner.service_actor', PostgresIdentity::at(eventsCommandsClock())->addActor($class, $state)->id->toString());

    return $journal;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function eventsCommand(string $command, array $arguments = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call($command, $arguments);

    return [$status, $artisan->output()];
}

it('runs the critical lane until idle as the service actor', function (): void {
    $journal = eventsCommandsWorld();
    new CommittedEvents(eventsCommandsClock())->commit(EventStream::Interactive, [CounterRaised::of('a', 1), CounterRaised::of('b', 1)]);

    [$status, $output] = eventsCommand('cms:events:run', ['--until-idle' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('Lane critical ran as ')
        ->and($output)->toContain(': 2 handled')
        ->and($journal->calls())->toBe(['a@1 try 1', 'b@1 try 1']);
});

it('parks, lists and releases an aggregate', function (): void {
    $journal = eventsCommandsWorld();
    $journal->break('bad');
    new CommittedEvents(eventsCommandsClock())->commit(EventStream::Interactive, [CounterRaised::of('bad', 1), CounterRaised::of('bad', 2)]);

    [$ran, $runOutput] = eventsCommand('cms:events:run', ['--until-idle' => true]);
    [$listed, $list] = eventsCommand('cms:events:parked', ['subscription' => 'test.counters']);

    expect($ran)->toBe(0)
        ->and($runOutput)->toContain('parked test.counters counter:bad after 2 tries')
        ->and($listed)->toBe(0)
        ->and($list)->toMatch('/^test\.counters counter:bad 2 tries, parked \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/m')
        ->and($list)->toContain('1 parked.');

    $journal->fix('bad');
    [$released, $releaseOutput] = eventsCommand('cms:events:release', ['subscription' => 'test.counters', 'aggregate' => 'counter:bad']);
    [, $afterRelease] = eventsCommand('cms:events:parked');

    expect($released)->toBe(0)
        ->and($releaseOutput)->toContain('Released counter:bad for test.counters, parked after 2 tries')
        ->and($afterRelease)->toMatch('/^test\.counters counter:bad 2 tries, parked \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z, released \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/m');

    [$again] = eventsCommand('cms:events:run', ['--until-idle' => true]);

    expect($again)->toBe(0)
        ->and(array_slice($journal->calls(), -1))->toBe(['bad@2 try 1 release'])
        ->and(eventsCommand('cms:events:parked')[1])->toContain('0 parked.');
});

it('refuses to release what it cannot', function (string $subscription, string $aggregate, int $status, string $message): void {
    eventsCommandsWorld();

    [$exit, $output] = eventsCommand('cms:events:release', ['subscription' => $subscription, 'aggregate' => $aggregate]);

    expect($exit)->toBe($status)
        ->and($output)->toContain($message);
})->with([
    'an unknown subscription' => ['test.gone', 'counter:bad', 65, 'No registered subscriber has the subscription "test.gone"'],
    'an aggregate that is not parked' => ['test.counters', 'counter:bad', 65, 'The aggregate counter:bad is not parked'],
    'an aggregate without a type' => ['test.counters', 'bad', 64, 'The aggregate is written <type>:<id>'],
    'an invalid subscription name' => ['Test Counters', 'counter:bad', 64, 'must be dot-separated snake_case segments'],
]);

it('refuses to run without a service actor it can run as', function (?ActorClass $class, ActorState $state, int $status, string $message): void {
    if ($class instanceof ActorClass) {
        eventsCommandsWorld($class, $state);
    } else {
        eventsCommandsWorld();
        config()->set('cbox-cms.events.runner.service_actor');
    }

    [$exit, $output] = eventsCommand('cms:events:run', ['--until-idle' => true]);

    expect($exit)->toBe($status)
        ->and($output)->toContain($message);
})->with([
    'none configured' => [null, ActorState::Active, 78, 'names none'],
    'a staff actor' => [ActorClass::Staff, ActorState::Active, 78, 'is a staff actor'],
    'a deactivated service actor' => [ActorClass::Service, ActorState::Deactivated, 77, 'is deactivated, not active'],
]);

it('refuses a lane it does not run and invalid settings', function (string $lane, ?int $batchSize, string $message): void {
    eventsCommandsWorld();

    if ($batchSize !== null) {
        config()->set('cbox-cms.events.runner.batch_size', $batchSize);
    }

    [$exit, $output] = eventsCommand('cms:events:run', ['--until-idle' => true, '--lane' => $lane]);

    expect($exit)->toBe(64)
        ->and($output)->toContain($message);
})->with([
    'a lane not built yet' => ['standard', null, 'the standard lane is not built yet'],
    'no lane' => ['urgent', null, '--lane must be one of critical, standard, external, revalidate, background'],
    'a batch size of 0' => ['critical', 0, 'cbox-cms.events.runner.batch_size must be a whole number from 1 to 10000'],
]);

it('refuses to list the parkings of a subscription name that is not one, with the reason', function (): void {
    eventsCommandsWorld();
    $reason = null;

    try {
        SubscriptionArguments::subscription('Not A Name');
    } catch (InvalidArgumentException $invalid) {
        $reason = $invalid->getMessage();
    }

    [$exit, $output] = eventsCommand('cms:events:parked', ['subscription' => 'Not A Name']);

    expect($exit)->toBe(64)
        ->and($reason)->toBeString()
        ->and($output)->toContain((string) $reason)
        ->and($output)->not->toContain('parked.');
});

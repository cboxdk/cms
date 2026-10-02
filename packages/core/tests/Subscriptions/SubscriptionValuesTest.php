<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Adapter\SubscriptionLock;
use Cbox\Cms\Core\Subscriptions\Boundary\RunnerConfig;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\LaneState;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriberFailed;
use Closure;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

/*
 * The event runner's values: the aggregate key, the delivery, the settings and their backoff, the
 * configuration reader and the subscription lock key.
 */

it('writes an aggregate as <type>:<id> and reads it back, splitting at the first colon', function (): void {
    $key = AggregateKey::fromString('entry:urn:x:1');

    expect($key->type->value)->toBe('entry')
        ->and($key->id->value)->toBe('urn:x:1')
        ->and($key->toString())->toBe('entry:urn:x:1')
        ->and($key->equals(AggregateKey::fromString('entry:urn:x:1')))->toBeTrue()
        ->and($key->equals(AggregateKey::fromString('entry:urn:x:2')))->toBeFalse()
        ->and($key->equals(AggregateKey::fromString('node:urn:x:1')))->toBeFalse();
});

it('refuses an aggregate that is not <type>:<id>', function (string $value): void {
    expect(fn (): AggregateKey => AggregateKey::fromString($value))->toThrow(InvalidEvent::class);
})->with(['no colon' => ['entry'], 'no type' => [':1'], 'no id' => ['entry:'], 'a space in the id' => ['entry:a b'], 'an upper-case type' => ['Entry:1']]);

it('counts a delivery\'s attempts from 1', function (): void {
    $actor = ActorId::fromString('01960000-0000-7000-8000-00000000000a');

    expect(new Delivery($actor)->attempt)->toBe(1)
        ->and(new Delivery($actor)->release)->toBeFalse()
        ->and(fn (): Delivery => new Delivery($actor, 0))->toThrow(InvalidArgumentException::class, 'attempt 1 or later, not 0');
});

it('doubles the backoff after each failed try up to its maximum', function (): void {
    $settings = new RunnerSettings(null, backoffBaseMs: 100, backoffMaxMs: 1_000);

    expect(array_map($settings->backoff(...), [1, 2, 3, 4, 5, 60]))->toBe([100, 200, 400, 800, 1_000, 1_000])
        ->and(new RunnerSettings(null, backoffBaseMs: 300, backoffMaxMs: 300)->backoff(3))->toBe(300);
});

it('refuses settings outside their ranges', function (Closure $settings, string $message): void {
    expect($settings)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'batch size 0' => [static fn (): RunnerSettings => new RunnerSettings(null, batchSize: 0), 'batch_size must be a whole number from 1 to 10000; it is 0'],
    'a budget of 2 s' => [static fn (): RunnerSettings => new RunnerSettings(null, batchBudgetMs: 2_000), 'batch_budget_ms must be a whole number from 1 to 1900; it is 2000'],
    'no attempts' => [static fn (): RunnerSettings => new RunnerSettings(null, maxAttempts: 0), 'max_attempts must be a whole number from 1 to 100'],
    'a maximum below the base' => [static fn (): RunnerSettings => new RunnerSettings(null, backoffBaseMs: 500, backoffMaxMs: 400), 'backoff_max_ms must be a whole number from 500 to 60000'],
    'no idle wait' => [static fn (): RunnerSettings => new RunnerSettings(null, idleSleepMs: 0), 'idle_sleep_ms must be a whole number from 1 to 60000'],
]);

it('has the defaults of the configuration file', function (): void {
    $settings = new RunnerSettings(null);

    expect([$settings->batchSize, $settings->batchBudgetMs, $settings->maxAttempts, $settings->backoffBaseMs, $settings->backoffMaxMs, $settings->idleSleepMs])
        ->toBe([100, 1_000, 5, 100, 5_000, 200]);
});

it('takes each setting at the bounds of its range, and refuses a backoff base of 0', function (): void {
    $lowest = new RunnerSettings(null, batchSize: 1, batchBudgetMs: 1, maxAttempts: 1, backoffBaseMs: 1, backoffMaxMs: 1, idleSleepMs: 1);
    $highest = new RunnerSettings(null, batchSize: RunnerSettings::MAX_BATCH_SIZE, batchBudgetMs: RunnerSettings::MAX_BATCH_BUDGET_MS, maxAttempts: RunnerSettings::MAX_ATTEMPTS, backoffBaseMs: RunnerSettings::MAX_WAIT_MS, backoffMaxMs: RunnerSettings::MAX_WAIT_MS, idleSleepMs: RunnerSettings::MAX_WAIT_MS);

    expect([$lowest->batchSize, $lowest->batchBudgetMs, $lowest->maxAttempts, $lowest->backoffBaseMs, $lowest->backoffMaxMs, $lowest->idleSleepMs])->toBe([1, 1, 1, 1, 1, 1])
        ->and([$highest->batchSize, $highest->batchBudgetMs, $highest->maxAttempts, $highest->backoffBaseMs, $highest->backoffMaxMs, $highest->idleSleepMs])->toBe([10_000, 1_900, 100, 60_000, 60_000, 60_000])
        ->and(static fn (): RunnerSettings => new RunnerSettings(null, backoffBaseMs: 0))->toThrow(InvalidArgumentException::class, 'backoff_base_ms must be a whole number from 1 to 60000; it is 0')
        ->and(static fn (): RunnerSettings => new RunnerSettings(null, batchSize: 10_001))->toThrow(InvalidArgumentException::class, 'batch_size must be a whole number from 1 to 10000; it is 10001');
});

it('reads the runner\'s settings from cbox-cms.events.runner', function (): void {
    $settings = RunnerConfig::read(new Repository(['cbox-cms' => ['events' => ['runner' => [
        'service_actor' => '01960000-0000-7000-8000-00000000000a',
        'batch_size' => 7,
        'batch_budget_ms' => 500,
        'max_attempts' => 9,
        'backoff_base_ms' => 10,
        'backoff_max_ms' => 20,
        'idle_sleep_ms' => 30,
    ]]]]));

    expect($settings->serviceActor?->toString())->toBe('01960000-0000-7000-8000-00000000000a')
        ->and([$settings->batchSize, $settings->batchBudgetMs, $settings->maxAttempts, $settings->backoffBaseMs, $settings->backoffMaxMs, $settings->idleSleepMs])
        ->toBe([7, 500, 9, 10, 20, 30])
        ->and(RunnerConfig::read(new Repository([]))->serviceActor)->toBeNull()
        ->and(RunnerConfig::read(new Repository([]))->batchSize)->toBe(100);
});

it('refuses runner settings that are not of their type', function (array $runner, string $message): void {
    expect(fn (): RunnerSettings => RunnerConfig::read(new Repository(['cbox-cms' => ['events' => ['runner' => $runner]]])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a batch size as text' => [['batch_size' => '100'], 'cbox-cms.events.runner.batch_size must be a whole number; it is string'],
    'a service actor that is not a UUIDv7' => [['service_actor' => 'robot'], 'service_actor must be the UUIDv7 of a service actor, or null'],
    'a service actor that is not text' => [['service_actor' => 7], 'service_actor must be the UUIDv7 of a service actor, or null'],
]);

it('derives a fixed lock key per subscription', function (): void {
    $key = SubscriptionLock::of(new SubscriptionName('fragments.invalidate'));

    expect($key)->toBe(SubscriptionLock::of(new SubscriptionName('fragments.invalidate')))
        ->and($key)->not->toBe(SubscriptionLock::of(new SubscriptionName('fragments.invalidat')))
        ->and(bin2hex(pack('J', $key)))->toBe(substr(hash('sha256', '["cbox_cms.subscription.v1","fragments.invalidate"]'), 0, 16));
});

it('says which unit a subscriber failed on and why, keeps the cause and has the exception code 0', function (): void {
    $cause = new RuntimeException('the store is down');
    $failed = new SubscriberFailed('test.counters|interactive|7.3', 2, $cause);

    expect([$failed->getMessage(), $failed->getCode(), $failed->getPrevious(), $failed->unit, $failed->index])
        ->toBe(['The subscriber failed on test.counters|interactive|7.3: the store is down', 0, $cause, 'test.counters|interactive|7.3', 2]);
});

it('counts nothing before the first batch, and each committed batch and busy subscription once', function (): void {
    $state = new LaneState;
    $actor = ActorId::fromString('01960000-0000-7000-8000-000000000001');
    $fresh = $state->report(Lane::Critical, $actor);

    $state->committed('q', new BatchProgress(handled: 2));
    $state->committed('q', new BatchProgress(passed: 1));
    $state->busy();
    $after = $state->report(Lane::Critical, $actor);

    expect([$fresh->batches, $fresh->handled, $fresh->passed, $fresh->failures, $fresh->busy])->toBe([0, 0, 0, 0, 0])
        ->and([$after->batches, $after->handled, $after->passed, $after->busy])->toBe([2, 2, 1, 1])
        ->and($state->failures('unit'))->toBe(0);
});

it('waits until the earliest failed unit is due, not at all once it is, and not while a queue has units to hand again', function (): void {
    $waiting = new LaneState;
    $waiting->failed('first', 'a', 0, 300);
    $waiting->failed('second', 'b', 0, 200);
    $again = new LaneState;
    $again->failed('first', 'a', 2, 900);

    expect(new LaneState()->nextDueIn(0))->toBeNull()
        ->and($waiting->nextDueIn(150))->toBe(50)
        ->and($waiting->nextDueIn(200))->toBe(0)
        ->and($waiting->nextDueIn(400))->toBe(0)
        ->and($again->nextDueIn(100))->toBe(0);
});

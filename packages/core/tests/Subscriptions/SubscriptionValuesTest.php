<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Adapter\SubscriptionLock;
use Cbox\Cms\Core\Subscriptions\Boundary\RunnerConfig;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Closure;
use Illuminate\Config\Repository;
use InvalidArgumentException;

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

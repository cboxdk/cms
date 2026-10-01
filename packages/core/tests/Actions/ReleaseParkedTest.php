<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Actions\ListParked;
use Cbox\Cms\Core\Subscriptions\Actions\ReleaseParked;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedFilter;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\ReleaseRefused;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Core\Tests\Subscriptions\LaneWorld;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The release and the list of parked aggregates (PRD 7.8), with the fake log (GUARDRAILS 9): a
 * release marks the parking released for the runner, and refuses a subscription no subscriber has
 * and an aggregate that is not parked.
 */

/**
 * Parks counter:<id> for the subscription, as a runner does after its tries.
 */
function releaseParkedPark(LaneWorld $world, string $subscription, string $id): void
{
    [$event] = $world->log->record(EventStream::Interactive, [CounterRaised::of($id, 1)]);
    $name = new SubscriptionName($subscription);
    $world->log->transaction($name, AccessContext::anonymous(), static function () use ($world, $name, $event): BatchProgress {
        $world->log->park($name, $event, 5);

        return new BatchProgress;
    });
}

/**
 * The refusal of a release of the aggregate for the subscription.
 */
function releaseParkedRefusal(LaneWorld $world, string $subscription, string $aggregate): ReleaseRefused
{
    try {
        releaseParked($world)->release(new ParkedRelease(new SubscriptionName($subscription), AggregateKey::fromString($aggregate)));
    } catch (ReleaseRefused $refused) {
        return $refused;
    }

    throw new AssertionFailedError('The release is refused.');
}

/**
 * @param  list<ParkedAggregate>  $parked
 * @return list<string>
 */
function releaseParkedNames(array $parked): array
{
    return array_map(static fn (ParkedAggregate $row): string => $row->subscription->value.' '.$row->aggregate->toString(), $parked);
}

function releaseParked(LaneWorld $world): ReleaseParked
{
    return new ReleaseParked($world->log, new FakeLaneSubscribers($world->bindings));
}

it('marks a parked aggregate released and keeps the first release\'s time', function (): void {
    $world = new LaneWorld;
    releaseParkedPark($world, 'test.counters', 'bad');
    $release = new ParkedRelease(new SubscriptionName('test.counters'), AggregateKey::fromString('counter:bad'));

    $first = releaseParked($world)->release($release);
    $second = releaseParked($world)->release($release);

    expect($first->isReleased())->toBeTrue()
        ->and($first->attempts)->toBe(5)
        ->and($first->aggregate->toString())->toBe('counter:bad')
        ->and($second->releasedAt)->toEqual($first->releasedAt)
        ->and($world->log->released(new SubscriptionName('test.counters'), 10))->toHaveCount(1);
});

it('refuses a subscription no registered subscriber has', function (): void {
    $world = new LaneWorld;
    releaseParkedPark($world, 'test.gone', 'bad');

    $refused = releaseParkedRefusal($world, 'test.gone', 'counter:bad');

    expect($refused->errorCode)->toBe('subscription_unknown');

    expect($world->log->released(new SubscriptionName('test.gone'), 10))->toBe([]);
});

it('refuses an aggregate that is not parked for the subscription', function (): void {
    $world = new LaneWorld;
    releaseParkedPark($world, 'test.counters', 'bad');

    $refused = releaseParkedRefusal($world, 'test.counters', 'counter:good');

    expect($refused->errorCode)->toBe('subscription_not_parked')
        ->and($refused->getMessage())->toContain('counter:good is not parked for the subscription "test.counters"');
});

it('lists the parked aggregates of one subscription or of all', function (): void {
    $world = new LaneWorld;
    $world->bindings[] = RecordingSubscriber::bound(new SubscriberJournal, 'test.others');
    releaseParkedPark($world, 'test.counters', 'bad');
    releaseParkedPark($world, 'test.others', 'worse');
    $list = new ListParked($world->log);

    expect(releaseParkedNames($list->list(new ParkedFilter)))->toBe(['test.counters counter:bad', 'test.others counter:worse'])
        ->and(releaseParkedNames($list->list(new ParkedFilter(new SubscriptionName('test.others')))))->toBe(['test.others counter:worse']);
});

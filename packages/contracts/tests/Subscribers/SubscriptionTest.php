<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Subscribers;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Attributes\UnknownEvent;
use Cbox\Cms\Contracts\Attributes\UnknownLane;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Subscribers\InvalidSubscriptionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Contracts\Tests\Fixtures\ReleaseVariant;
use Cbox\Cms\Contracts\Tests\Fixtures\VariantReleased;
use Cbox\Cms\Contracts\Tests\Fixtures\VariantWithdrawn;
use InvalidArgumentException;

/*
 * The subscription contract (PRD 7.6): #[Subscription] names the subscription, lists its event
 * classes, its lane and the projection it acknowledges, and refuses what cms:build would report.
 */

it('has the five lanes of PRD 7.6, in order', function (): void {
    expect(array_map(static fn (Lane $lane): string => $lane->value, Lane::cases()))
        ->toBe(['critical', 'standard', 'external', 'revalidate', 'background']);
});

it('keeps a subscription\'s name, events, lane and projection', function (): void {
    $subscription = new Subscription('fragments.invalidate', events: [VariantWithdrawn::class, VariantReleased::class], lane: Lane::Critical, projection: 'fragments');

    expect($subscription->name()->value)->toBe('fragments.invalidate')
        ->and($subscription->events)->toBe([VariantReleased::class, VariantWithdrawn::class])
        ->and($subscription->lane)->toBe(Lane::Critical)
        ->and($subscription->projection())->toEqual(new ProjectionName('fragments'));
});

it('sorts the events, so two declarations of the same events are equal', function (): void {
    expect(new Subscription('a.b', [VariantReleased::class, VariantWithdrawn::class], Lane::Standard))
        ->toEqual(new Subscription('a.b', [VariantWithdrawn::class, '\\'.VariantReleased::class], Lane::Standard));
});

it('acknowledges no projection when none is given', function (): void {
    expect(new Subscription('webhooks.deliver', [VariantReleased::class], Lane::External)->projection())->toBeNull();
});

it('refuses what cms:build would report', function (callable $build, string $exception, string $message): void {
    expect($build)->toThrow($exception, $message);
})->with([
    'a lane that is a string' => [static fn (): Subscription => new Subscription('a.b', [VariantReleased::class], 'critical'), UnknownLane::class, '#[Subscription] names the lane "critical", which is not a lane. Name a case of Cbox\Cms\Contracts\Subscribers\Lane: Lane::Critical, Lane::Standard, Lane::External, Lane::Revalidate, Lane::Background.'],
    'an event class that does not exist' => [static fn (): Subscription => new Subscription('a.b', ['Acme\NoSuchEvent'], Lane::Critical), UnknownEvent::class, '#[Subscription] lists the event class "Acme\NoSuchEvent", which does not exist.'],
    'a class that is not an event' => [static fn (): Subscription => new Subscription('a.b', [ReleaseVariant::class], Lane::Critical), UnknownEvent::class, '#[Subscription] lists the class "'.ReleaseVariant::class.'", which does not implement Cbox\Cms\Contracts\Events\Event.'],
    'no event' => [static fn (): Subscription => new Subscription('a.b', [], Lane::Critical), InvalidArgumentException::class, '#[Subscription] "a.b" lists no event.'],
    'an event twice' => [static fn (): Subscription => new Subscription('a.b', [VariantReleased::class, strtolower(VariantReleased::class)], Lane::Critical), InvalidArgumentException::class, 'lists the event class "'.strtolower(VariantReleased::class).'" twice.'],
    'a name that is not one' => [static fn (): Subscription => new Subscription('Fragments', [VariantReleased::class], Lane::Critical), InvalidSubscriptionName::class, 'The subscription name "Fragments" must be dot-separated snake_case segments of at most 63 characters'],
    'a projection that is not one' => [static fn (): Subscription => new Subscription('a.b', [VariantReleased::class], Lane::Critical, 'Fragments'), InvalidReceipt::class, 'got "Fragments"'],
]);

it('accepts subscription names of one or more snake_case segments up to 63 characters', function (): void {
    expect(new SubscriptionName('fragments')->value)->toBe('fragments')
        ->and(new SubscriptionName('acme.search.index_2')->value)->toBe('acme.search.index_2')
        ->and(new SubscriptionName(str_repeat('a', 63))->value)->toHaveLength(63)
        ->and(new SubscriptionName('a.b')->equals(new SubscriptionName('a.b')))->toBeTrue()
        ->and(new SubscriptionName('a.b')->equals(new SubscriptionName('a.c')))->toBeFalse();
});

it('refuses a subscription name that is not one, and cuts a long one in the message', function (string $name, string $message): void {
    expect(static fn (): SubscriptionName => new SubscriptionName($name))->toThrow(InvalidSubscriptionName::class, $message);
})->with([
    'too long' => [str_repeat('a', 64), 'The subscription name "'.str_repeat('a', 64).'"'],
    'empty' => ['', 'The subscription name ""'],
    'a leading digit' => ['1a', '"1a"'],
    'an empty segment' => ['a..b', '"a..b"'],
    'a trailing dot' => ['a.', '"a."'],
    'a dash' => ['a-b', '"a-b"'],
    'much too long' => [str_repeat('b', 80), '"'.str_repeat('b', 64).'..."'],
]);

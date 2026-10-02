<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\CdnUnavailable;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleased;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Fragments\InvalidationWorld;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;
use ReflectionClass;

/*
 * The invalidation subscriber, fragments.invalidate (PRD 7.6, 8.4, 8.12, 9.4), called directly
 * with stored content events and the fakes of the fragment store, the CDN driver, the receipt
 * store and the clock (GUARDRAILS 9). It covers success, a CDN that refuses the purge, and events
 * handled twice or out of order.
 */

it('purges the fragments of the entry behind a fence at the event position, purges the edge softly and acknowledges origin', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $world->write('page:other', 90, InvalidationWorld::OTHER);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::revised(2), $changeset, 100));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->fragments->fragmentsOf(InvalidationWorld::key()))->toBe([])
        ->and($world->fragments->read(new FragmentKey('page:other')))->not->toBeNull()
        ->and($world->write('page:a', 100))->toBeInstanceOf(FragmentFenced::class)
        ->and($world->write('page:a', 101))->toBeInstanceOf(FragmentStored::class)
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key()], PurgeMode::Soft)])
        ->and($world->origin($changeset))->toEqual(ProjectionStatus::acknowledged(new ProjectionName('origin'), $world->clock->now()));
});

it('keeps the fence for the configured seconds from the clock', function (): void {
    $world = new InvalidationWorld(fenceSeconds: 30);

    $world->handle($world->stored(InvalidationWorld::revised(2), $world->changeset('origin'), 100));

    $world->clock->advance(new DateInterval('PT29S'));
    expect($world->write('page:a', 100))->toBeInstanceOf(FragmentFenced::class);

    $world->clock->advance(new DateInterval('PT1S'));
    expect($world->write('page:a', 100))->toBeInstanceOf(FragmentStored::class);
});

it('invalidates the entry of entry.created as it does for variant.revised', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 50);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::created(), $changeset, 60));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->write('page:a', 60))->toBeInstanceOf(FragmentFenced::class)
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key()], PurgeMode::Soft)])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged);
});

it('purges hard on a CDN that cannot purge softly', function (): void {
    $world = new InvalidationWorld(softPurge: false);

    $world->handle($world->stored(InvalidationWorld::revised(2), $world->changeset('origin'), 100));

    expect($world->cdn->purgedWith(InvalidationWorld::key()))->toBe(PurgeMode::Hard);
});

it('leaves origin pending and throws when the CDN does not take the purge, and acknowledges it on the next try', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $changeset = $world->changeset('origin');
    $event = $world->stored(InvalidationWorld::revised(2), $changeset, 100);
    $world->cdn->interrupt();

    expect(fn () => $world->handle($event))->toThrow(CdnUnavailable::class);
    expect($world->origin($changeset)?->state)->toBe(ProjectionState::Pending)
        ->and($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->cdn->requests())->toBe([]);

    $world->cdn->restore();
    $world->handle($event, 2);

    expect($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged)
        ->and($world->cdn->requests())->toHaveCount(1);
});

it('changes nothing more when an event comes twice or an older version comes after a newer one', function (): void {
    $world = new InvalidationWorld;
    $older = $world->changeset('origin');
    $newer = $world->changeset('origin');
    $world->handle($world->stored(InvalidationWorld::revised(3), $newer, 200));
    $acknowledged = $world->origin($newer);

    $world->clock->advance(new DateInterval('PT1S'));
    $world->handle($world->stored(InvalidationWorld::revised(3), $newer, 200));
    $world->handle($world->stored(InvalidationWorld::revised(2), $older, 150));

    // The fence stays at the newer position: a fragment built between the two is still refused.
    expect($world->write('page:a', 180))->toBeInstanceOf(FragmentFenced::class)
        ->and($world->write('page:a', 201))->toBeInstanceOf(FragmentStored::class)
        ->and($world->origin($newer))->toEqual($acknowledged)
        ->and($world->origin($older)?->state)->toBe(ProjectionState::Acknowledged);
});

it('leaves a receipt without the projection, and a changeset without a receipt, alone', function (): void {
    $world = new InvalidationWorld;
    $other = $world->changeset('acme.search');
    $gone = new ChangesetId(new FakeIdGenerator(seed: 9)->next());

    $world->handle($world->stored(InvalidationWorld::revised(2), $other, 100));
    $world->handle($world->stored(InvalidationWorld::revised(3), $gone, 110));

    expect($world->receipts->find($other)?->projections)->toEqual([ProjectionStatus::pending(new ProjectionName('acme.search'))])
        ->and($world->receipts->find($gone))->toBeNull()
        ->and($world->cdn->requests())->toHaveCount(2);
});

it('refuses an event that carries no entry and purges nothing', function (): void {
    $world = new InvalidationWorld;
    $changeset = $world->changeset('origin');

    expect(fn () => $world->handle($world->stored(CounterRaised::of('a', 1), $changeset, 100)))->toThrow(InvalidEvent::class);
    expect($world->cdn->requests())->toBe([])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Pending);
});

it('invalidates the entry of variant.released and purges the edge softly, because the released content is a change', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::released(3, 2), $changeset, 100));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->write('page:a', 100))->toBeInstanceOf(FragmentFenced::class)
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key()], PurgeMode::Soft)])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged);
});

it('invalidates the entry of variant.unreleased and purges the edge hard, because the content is removed', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::unreleased(4, 2), $changeset, 100));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key()], PurgeMode::Hard)])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged);
});

it('invalidates the entry and the node of placement.created, so a 404 of the path below the node is purged too', function (): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $world->writeUnderNode('missing:harbour', 90);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::placed(), $changeset, 100));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->fragments->read(new FragmentKey('missing:harbour')))->toBeNull()
        ->and($world->fragments->fragmentsOf(InvalidationWorld::nodeKey()))->toBe([])
        ->and($world->writeUnderNode('missing:harbour', 100))->toBeInstanceOf(FragmentFenced::class)
        ->and($world->writeUnderNode('missing:harbour', 101))->toBeInstanceOf(FragmentStored::class)
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key(), InvalidationWorld::nodeKey()], PurgeMode::Soft)])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged);
});

it('purges the entry and the node of placement.visibility_changed softly when the placement goes live and hard when it is no longer live', function (Visibility $previous, Visibility $visibility, PurgeMode $mode): void {
    $world = new InvalidationWorld;
    $world->write('page:a', 90);
    $world->writeUnderNode('missing:harbour', 90);
    $changeset = $world->changeset('origin');

    $world->handle($world->stored(InvalidationWorld::windowed(2, $previous, $visibility), $changeset, 100));

    expect($world->fragments->read(new FragmentKey('page:a')))->toBeNull()
        ->and($world->fragments->read(new FragmentKey('missing:harbour')))->toBeNull()
        ->and($world->cdn->requests())->toEqual([new CdnPurge([InvalidationWorld::key(), InvalidationWorld::nodeKey()], $mode)])
        ->and($world->origin($changeset)?->state)->toBe(ProjectionState::Acknowledged);
})->with([
    'opened' => [Visibility::Hidden, Visibility::Live, PurgeMode::Soft],
    'window moved' => [Visibility::Live, Visibility::Live, PurgeMode::Soft],
    'unpublished' => [Visibility::Live, Visibility::Hidden, PurgeMode::Hard],
    'scheduled for later' => [Visibility::Live, Visibility::Scheduled, PurgeMode::Hard],
    'expired' => [Visibility::Live, Visibility::Expired, PurgeMode::Hard],
]);

it('subscribes on the critical lane to every event that changes what a fragment shows, and acknowledges origin', function (): void {
    $world = new InvalidationWorld;
    $world->handle($world->stored(InvalidationWorld::revised(2), $world->changeset('origin'), 100));
    $subscription = new ReflectionClass(InvalidateFragments::class)->getAttributes(Subscription::class)[0]->newInstance();

    expect($subscription->events)->toBe([EntryCreated::class, VariantReleased::class, VariantRevised::class, VariantUnreleased::class, PlacementCreated::class, PlacementVisibilityChanged::class])
        ->and($subscription->lane)->toBe(Lane::Critical)
        ->and($subscription->projection)->toBe(InvalidateFragments::PROJECTION);
});

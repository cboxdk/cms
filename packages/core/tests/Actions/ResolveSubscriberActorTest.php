<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Addons\Actions\ResolveSubscriberActor;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Cbox\Cms\Core\Addons\Domain\SubscriberActorUnavailable;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

/*
 * The actor a subscriber runs as (PRD 13.1, invariant 21): an addon's subscriber runs as the
 * addon's own active service actor, never as the system; a subscriber of no addon has none. The
 * action runs with FakeIdentity, the testkit's ActorDirectory (GUARDRAILS 9).
 */

function addonSubscriber(?AddonNamespace $addon): SubscriberEntry
{
    return new SubscriberEntry('Acme\\Reviews\\IndexReviews', 'acme/cms-reviews', new SubscriptionName('reviews.index'), Lane::Standard, null, [
        new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1)),
    ], $addon);
}

it('runs an addon\'s subscriber as the addon\'s own active service actor', function (): void {
    $identity = new FakeIdentity;
    $service = $identity->addActor(ActorClass::Service);
    $reviews = new AddonNamespace('reviews');

    $actor = new ResolveSubscriberActor(new ServiceActors(['reviews' => $service->id]), $identity)->for(addonSubscriber($reviews));

    expect($actor)->toEqual($service);
});

it('gives a subscriber of no addon no actor', function (): void {
    expect(new ResolveSubscriberActor(new ServiceActors, new FakeIdentity)->for(addonSubscriber(null)))->toBeNull();
});

it('refuses to run an addon\'s subscriber without a service actor configured for the addon', function (): void {
    $identity = new FakeIdentity;
    $other = $identity->addActor(ActorClass::Service);

    expect(static fn (): mixed => new ResolveSubscriberActor(new ServiceActors(['glossary' => $other->id]), $identity)->for(addonSubscriber(new AddonNamespace('reviews'))))
        ->toThrow(SubscriberActorUnavailable::class, 'The subscriber Acme\\Reviews\\IndexReviews of addon "reviews" cannot run: no service actor is configured for the addon. Set cbox-cms.addons.service_actors.reviews');
});

it('refuses to run an addon\'s subscriber whose configured actor does not exist', function (): void {
    $missing = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000a001');

    expect(static fn (): mixed => new ResolveSubscriberActor(new ServiceActors(['reviews' => $missing]), new FakeIdentity)->for(addonSubscriber(new AddonNamespace('reviews'))))
        ->toThrow(SubscriberActorUnavailable::class, 'no actor has the configured service actor id 01936f5e-8a2b-7c3d-9e4f-00000000a001');
});

it('refuses to run an addon\'s subscriber as an actor that is not an active service actor', function (ActorClass $class, ActorState $state): void {
    $identity = new FakeIdentity;
    $actor = $identity->addActor($class, $state);

    expect(static fn (): mixed => new ResolveSubscriberActor(new ServiceActors(['reviews' => $actor->id]), $identity)->for(addonSubscriber(new AddonNamespace('reviews'))))
        ->toThrow(SubscriberActorUnavailable::class, sprintf('its service actor %s is a %s actor in the state %s, and an addon runs only as an active service actor', $actor->id->toString(), $class->value, $state->value));
})->with([
    'a staff actor' => [ActorClass::Staff, ActorState::Active],
    'an end user' => [ActorClass::EndUser, ActorState::Active],
    'a deactivated service actor' => [ActorClass::Service, ActorState::Deactivated],
    'a pending service actor' => [ActorClass::Service, ActorState::Pending],
    'a deprovisioned service actor' => [ActorClass::Service, ActorState::Deprovisioned],
]);

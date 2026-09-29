<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryName;

/*
 * The registry's subscriber entries check their values, so an entry read from a damaged cache fails
 * as one built wrongly in code does, and the compiled registry answers which subscribers receive an
 * event class and which projections that event affects (PRD 7.6, 8.4, 13.2).
 */

/**
 * @param  list<string>  $events
 */
function subscriberEntry(string $class, string $name, Lane $lane, ?string $projection, array $events): SubscriberEntry
{
    return new SubscriberEntry(
        $class,
        'acme/a',
        new SubscriptionName($name),
        $lane,
        $projection === null ? null : new ProjectionName($projection),
        array_map(static fn (string $event): SubscribedEvent => new SubscribedEvent($event, new EventType('a.'.strtolower(str_replace('\\', '_', $event)), 1)), $events),
    );
}

/**
 * @param  list<ProjectionName>  $projections
 * @return list<string>
 */
function projectionNames(array $projections): array
{
    return array_map(static fn (ProjectionName $projection): string => $projection->value, $projections);
}

it('maps an event class to the projections of the subscribers that receive it, each once and sorted', function (): void {
    $registry = new CompiledRegistry([], [], [], [
        subscriberEntry('App\Fragments', 'a.fragments', Lane::Critical, 'fragments', ['App\Created', 'App\Released']),
        subscriberEntry('App\Search', 'a.search', Lane::Standard, 'search', ['App\Released']),
        subscriberEntry('App\Edge', 'a.edge', Lane::Critical, 'edge', ['App\Released', 'App\Withdrawn']),
        subscriberEntry('App\EdgeAgain', 'a.edge_again', Lane::Critical, 'edge', ['App\Released']),
        subscriberEntry('App\Webhooks', 'a.webhooks', Lane::External, null, ['App\Created', 'App\Released']),
    ]);

    expect(projectionNames($registry->projectionsFor('App\Released')))->toBe(['edge', 'fragments', 'search'])
        ->and(projectionNames($registry->projectionsFor('App\Created')))->toBe(['fragments'])
        ->and(projectionNames($registry->projectionsFor('App\Withdrawn')))->toBe(['edge'])
        ->and(projectionNames($registry->projectionsFor('\app\withdrawn')))->toBe(['edge'])
        ->and($registry->projectionsFor('App\Archived'))->toBe([])
        ->and(CompiledRegistry::empty()->projectionsFor('App\Released'))->toBe([])
        ->and(array_map(static fn (SubscriberEntry $subscriber): string => $subscriber->class, $registry->subscribersOf('App\Created')))->toBe(['App\Fragments', 'App\Webhooks'])
        ->and(array_map(static fn (SubscriberEntry $subscriber): string => $subscriber->class, $registry->subscribersOf('APP\RELEASED')))->toBe(['App\Fragments', 'App\Search', 'App\Edge', 'App\EdgeAgain', 'App\Webhooks'])
        ->and($registry->subscribersOf('App\Archived'))->toBe([])
        ->and($registry->count(RegistryName::Subscribers))->toBe(5);
});

it('affects no projection through a subscriber that acknowledges none', function (): void {
    $registry = new CompiledRegistry([], [], [], [subscriberEntry('App\Webhooks', 'a.webhooks', Lane::External, null, ['App\Created'])]);

    expect($registry->projectionsFor('App\Created'))->toBe([])
        ->and($registry->subscribersOf('App\Created'))->toHaveCount(1);
});

it('keeps a subscriber entry\'s values and tells which events it receives', function (): void {
    $entry = subscriberEntry('App\Fragments', 'a.fragments', Lane::Revalidate, 'fragments', ['App\Created', 'App\Released']);

    expect([$entry->class, $entry->package, $entry->name->value, $entry->lane, $entry->projection?->value])
        ->toBe(['App\Fragments', 'acme/a', 'a.fragments', Lane::Revalidate, 'fragments'])
        ->and(array_map(static fn (SubscribedEvent $event): string => $event->class, $entry->events))->toBe(['App\Created', 'App\Released'])
        ->and($entry->receives('App\Created'))->toBeTrue()
        ->and($entry->receives('\app\released'))->toBeTrue()
        ->and($entry->receives('App\Withdrawn'))->toBeFalse();
});

it('refuses a subscriber entry with a value it cannot hold', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidRegistryEntry::class, $message);
})->with([
    'no events' => [static fn (): SubscriberEntry => subscriberEntry('App\S', 'a.s', Lane::Critical, null, []), 'Subscriber "App\S" receives no event. A subscriber receives at least one.'],
    'events out of order' => [static fn (): SubscriberEntry => subscriberEntry('App\S', 'a.s', Lane::Critical, null, ['App\B', 'App\A']), 'Subscriber "App\S" receives the events App\B, App\A. Each event class is listed once, sorted by class.'],
    'an event twice' => [static fn (): SubscriberEntry => subscriberEntry('App\S', 'a.s', Lane::Critical, null, ['App\A', 'app\a']), 'Subscriber "App\S" receives the events App\A, app\a.'],
    'a subscriber class that is not one' => [static fn (): SubscriberEntry => subscriberEntry('App\\', 'a.s', Lane::Critical, null, ['App\A']), 'The subscriber class "App\\" is not a fully qualified class name.'],
    'a package that is not one' => [static fn (): SubscriberEntry => new SubscriberEntry('App\S', 'acme', new SubscriptionName('a.s'), Lane::Critical, null, [new SubscribedEvent('App\A', new EventType('a.a', 1))]), 'The package "acme" is not a Composer package name.'],
    'an event class that is not one' => [static fn (): SubscribedEvent => new SubscribedEvent('1Event', new EventType('a.a', 1)), 'The event class "1Event" is not a fully qualified class name.'],
]);

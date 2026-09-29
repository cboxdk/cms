<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A subscriber in the registry (PRD 7.6, 13.2): the subscriber class and its package, the name of
 * its subscription, its lane, the projection it acknowledges on the receipt or null, and the events
 * it receives, at least one, each class once, sorted by class without case. A subscription name
 * belongs to one subscriber.
 *
 * A subscriber of an addon's package also names the addon, whose own service identity it runs as
 * (PRD 13.1, invariant 21); a subscriber of a package without a manifest names none.
 */
#[Experimental]
final readonly class SubscriberEntry
{
    public string $class;

    public string $package;

    /** @var list<SubscribedEvent> */
    public array $events;

    /**
     * @param  list<SubscribedEvent>  $events  each class once, sorted by class without case
     */
    public function __construct(
        string $class,
        string $package,
        public SubscriptionName $name,
        public Lane $lane,
        public ?ProjectionName $projection,
        array $events,
        public ?AddonNamespace $addon = null,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('subscriber class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->events = InvalidRegistryEntry::checkEvents($class, $events);
    }

    /**
     * Whether the subscriber receives events of the class. PHP class names are compared without
     * case, as PHP compares them.
     */
    public function receives(string $eventClass): bool
    {
        $wanted = strtolower(ltrim($eventClass, '\\'));

        return array_any($this->events, fn (SubscribedEvent $event): bool => strtolower($event->class) === $wanted);
    }
}

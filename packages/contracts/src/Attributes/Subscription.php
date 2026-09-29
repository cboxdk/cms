<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Subscribers\InvalidSubscriptionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use InvalidArgumentException;

/**
 * Declares a subscriber's subscription (PRD 7.6, 13.2): its name, the event classes it receives,
 * its lane and the projection it acknowledges on the receipt, if any, for example
 * #[Subscription('fragments.invalidate', events: [VariantReleased::class], lane: Lane::Critical, projection: 'fragments')].
 *
 * The class carrying it implements Subscriber and is a final readonly class. cms:build compiles it
 * into subscribers.php; the registry then answers which projections an event class affects, which
 * the kernel lists on the receipt of a changeset that writes such an event (PRD 8.4).
 *
 * - The name has the form of SubscriptionName and belongs to one subscriber; the event log keeps
 *   the subscription's cursor under it.
 * - Each event class exists and implements Event, and is listed once. The classes are sorted, so
 *   two declarations of the same events are equal whatever order they list them in.
 * - The lane is a case of Lane; anything else throws UnknownLane.
 * - The projection is a ProjectionName, such as "fragments", or null for a subscriber whose work
 *   no receipt waits for, such as a webhook.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Subscription
{
    /** @var list<class-string<Event>> sorted */
    public array $events;

    public Lane $lane;

    /**
     * @param  list<string>  $events  the event classes, such as VariantReleased::class
     *
     * @throws InvalidSubscriptionName when the name is not a subscription name
     * @throws UnknownEvent when an event class does not exist or does not implement Event
     * @throws UnknownLane when the lane is not a case of Lane
     * @throws InvalidReceipt when the projection is not a projection name
     * @throws InvalidArgumentException when no event is listed, or one is listed twice
     */
    public function __construct(
        public string $name,
        array $events,
        Lane|string $lane,
        public ?string $projection = null,
    ) {
        new SubscriptionName($name);

        if ($projection !== null) {
            new ProjectionName($projection);
        }

        if ($events === []) {
            throw new InvalidArgumentException(sprintf('#[Subscription] "%s" lists no event. List the classes of the events it receives.', $name));
        }

        $classes = [];

        foreach ($events as $event) {
            $event = ltrim($event, '\\');

            if (! class_exists($event)) {
                throw UnknownEvent::missing($event);
            }

            if (! is_subclass_of($event, Event::class)) {
                throw UnknownEvent::notAnEvent($event);
            }

            if (isset($classes[strtolower($event)])) {
                throw new InvalidArgumentException(sprintf('#[Subscription] "%s" lists the event class "%s" twice.', $name, $event));
            }

            $classes[strtolower($event)] = $event;
        }

        ksort($classes, SORT_STRING);

        $this->events = array_values($classes);
        $this->lane = $lane instanceof Lane ? $lane : throw UnknownLane::named($lane);
    }

    public function name(): SubscriptionName
    {
        return new SubscriptionName($this->name);
    }

    public function projection(): ?ProjectionName
    {
        return $this->projection === null ? null : new ProjectionName($this->projection);
    }
}

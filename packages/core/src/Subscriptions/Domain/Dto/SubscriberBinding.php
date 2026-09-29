<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;

/**
 * A registered subscriber with the instance the container built for it.
 */
#[Experimental]
final readonly class SubscriberBinding
{
    public function __construct(
        public SubscriberEntry $entry,
        public Subscriber $subscriber,
    ) {}

    /**
     * The event types the subscriber receives, as the log stores them.
     *
     * @return list<EventType>
     */
    public function types(): array
    {
        return array_map(static fn (SubscribedEvent $event): EventType => $event->type, $this->entry->events);
    }

    /**
     * Whether the subscriber receives events of the type, by name and payload version.
     */
    public function receives(EventType $type): bool
    {
        return array_any($this->entry->events, static fn (SubscribedEvent $event): bool => $event->type->equals($type));
    }
}

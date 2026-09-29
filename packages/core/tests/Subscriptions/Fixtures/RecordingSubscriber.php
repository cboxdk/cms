<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fixtures;

use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;

/**
 * The runner's test subscriber: it receives counter.raised and hands each event to its journal.
 */
final readonly class RecordingSubscriber implements Subscriber
{
    public function __construct(private SubscriberJournal $journal) {}

    /**
     * The subscriber registered as the subscription on the lane, receiving counter.raised.
     */
    public static function bound(SubscriberJournal $journal, string $subscription = 'test.counters', Lane $lane = Lane::Critical): SubscriberBinding
    {
        return new SubscriberBinding(self::entry($subscription, $lane), new self($journal));
    }

    public static function entry(string $subscription = 'test.counters', Lane $lane = Lane::Critical): SubscriberEntry
    {
        return new SubscriberEntry(
            self::class,
            'cboxdk/cms',
            new SubscriptionName($subscription),
            $lane,
            null,
            [new SubscribedEvent(CounterRaised::class, CounterRaised::type())],
        );
    }

    public function handle(StoredEvent $event, Delivery $delivery): void
    {
        $this->journal->record($event, $delivery);
    }
}

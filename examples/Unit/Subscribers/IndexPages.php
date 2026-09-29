<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;

/**
 * The search addon's subscriber: it indexes a page when it is published or withdrawn, on the
 * standard lane, and acknowledges the projection acme.search on the receipt. It is state-based:
 * the event says "page X is now at version V", and a version it has already indexed is ignored.
 */
#[Subscription('acme.search.index', events: [PagePublished::class, PageWithdrawn::class], lane: Lane::Standard, projection: 'acme.search')]
final readonly class IndexPages implements Subscriber
{
    public function __construct(private SearchIndex $index) {}

    public function handle(StoredEvent $event, Delivery $delivery): void
    {
        $page = $event->aggregate->id->toString();

        if (($this->index->pages[$page] ?? 0) >= $event->aggregate->version) {
            return;
        }

        $this->index->pages[$page] = $event->aggregate->version;
    }
}

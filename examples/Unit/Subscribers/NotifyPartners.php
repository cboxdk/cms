<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;

/**
 * The search addon's second subscriber: it tells partners that a page was published, on the
 * external lane. No receipt waits for a partner, so it acknowledges no projection.
 */
#[Subscription('acme.search.partners', events: [PagePublished::class], lane: Lane::External)]
final readonly class NotifyPartners implements Subscriber
{
    public function handle(StoredEvent $event): void {}
}

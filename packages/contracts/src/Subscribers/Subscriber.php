<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Subscribers;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\StoredEvent;

/**
 * A subscriber reacts to events from the event log after their changeset has committed (PRD 7.6,
 * phase 8 of PRD 6.2). The class carries #[Subscription], which names the subscription and
 * declares the event classes it receives, its lane and the projection it acknowledges on the
 * receipt, if any; cms:build compiles it into subscribers.php (PRD 13.2).
 *
 * A subscriber is state-based (PRD 7.4): an event means "aggregate X is now at version V, read the
 * state". Delivery is at least once and not in commit order, so handle() is idempotent per
 * (aggregate, version) and ignores a version older than the last it handled (PRD 7.7). It runs
 * outside any command transaction, may do IO, and never writes content: to change something it
 * issues a command (PRD 8.6). A subscriber is a final readonly class and keeps no state between
 * events.
 */
#[Experimental]
interface Subscriber
{
    public function handle(StoredEvent $event): void;
}

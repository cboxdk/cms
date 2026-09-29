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
 * (aggregate, version) and ignores a version older than the last it handled (PRD 7.7). A
 * subscriber is a final readonly class and keeps no state between events.
 *
 * The event runner of its lane (cms:events:run) calls handle() inside the runner's transaction on
 * the default connection, outside any command transaction: what the subscriber writes on that
 * connection commits together with the subscription's cursor, or neither does. It never begins,
 * commits or rolls back a transaction itself. It may do IO, and never writes content (PRD 8.6).
 * An exception from handle() rolls back its writes; the runner tries the event again with
 * backoff, and after a fixed number of tries parks the aggregate for this subscription (PRD 7.8).
 * The Delivery names the service identity it runs as and the attempt.
 */
#[Experimental]
interface Subscriber
{
    public function handle(StoredEvent $event, Delivery $delivery): void;
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Subscribers;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use InvalidArgumentException;

/**
 * How the event runner hands an event to a subscriber (PRD 7.6 to 7.8).
 *
 * - actor is the service identity the subscribers run as, the actor a subscriber names when it
 *   acts, never the system (PRD 6.5 invariant 21, 13.1). The runner checks before it runs that the
 *   actor exists, is a service actor and is active.
 * - attempt counts the tries of this event for this subscription, from 1. The runner tries again
 *   with exponential backoff after a failure and parks the aggregate after a fixed number of tries
 *   (PRD 7.7, 7.8).
 * - release is true when the event is the one handling of a released aggregate: the newest event
 *   of the aggregate the subscription has passed, handed once at the aggregate's current version
 *   after the aggregate was parked (PRD 7.8).
 */
#[Experimental]
final readonly class Delivery
{
    public function __construct(
        public ActorId $actor,
        public int $attempt = 1,
        public bool $release = false,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException(sprintf('A delivery is attempt 1 or later, not %d.', $attempt));
        }
    }
}

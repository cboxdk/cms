<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;

/**
 * A subscription a run did not hand any event to, because it has no actor it may run as (PRD 6.5
 * invariant 21): an addon's subscription whose service actor is not configured, unknown or not an
 * active service actor. The code is the catalog's, the reason says what to fix. Its cursor stays
 * where it was, so it misses nothing once the actor is fixed.
 */
#[Experimental]
final readonly class RefusedSubscription
{
    public function __construct(
        public SubscriptionName $subscription,
        public string $code,
        public string $reason,
    ) {}
}

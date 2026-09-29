<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;

/**
 * An aggregate a run parked for a subscription after $attempts failed tries, or parked again
 * after its release failed as often.
 */
#[Experimental]
final readonly class Parking
{
    public function __construct(
        public SubscriptionName $subscription,
        public AggregateKey $aggregate,
        public int $attempts,
    ) {}
}

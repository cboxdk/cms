<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;

/**
 * The release of an aggregate a subscription parked, once the fault is fixed (PRD 7.8).
 */
#[Experimental]
final readonly class ParkedRelease
{
    public function __construct(
        public SubscriptionName $subscription,
        public AggregateKey $aggregate,
    ) {}
}

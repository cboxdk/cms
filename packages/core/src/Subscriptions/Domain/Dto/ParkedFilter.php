<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;

/**
 * Which parked aggregates to list: those of one subscription, or of every subscription when null.
 */
#[Experimental]
final readonly class ParkedFilter
{
    public function __construct(
        public ?SubscriptionName $subscription = null,
    ) {}
}

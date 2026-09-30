<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;

/**
 * How many aggregates a subscription has parked and not yet released (PRD 7.8).
 */
#[Internal]
final readonly class ParkedCount
{
    public function __construct(
        public SubscriptionName $subscription,
        public int $count,
    ) {}
}

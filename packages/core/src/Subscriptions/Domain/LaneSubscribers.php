<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;

/**
 * The subscribers cms:build registered (PRD 7.6, 13.2).
 */
#[Internal]
interface LaneSubscribers
{
    /**
     * The subscribers of the lane, each with its entry, in registry order, by subscription name.
     *
     * @return list<SubscriberBinding>
     *
     * @throws UnusableSubscriber when a registered class is not a Subscriber
     */
    public function in(Lane $lane): array;

    /**
     * The registered subscription of that name, in any lane, or null.
     */
    public function named(SubscriptionName $subscription): ?SubscriberEntry;
}

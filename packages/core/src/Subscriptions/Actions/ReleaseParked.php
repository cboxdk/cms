<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\ReleaseRefused;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;

/**
 * Releases an aggregate a subscription parked, once the fault is fixed (PRD 7.8). It only marks
 * the parking released: the runner of the subscription's lane then hands the subscriber the
 * aggregate once, at its current version, and removes the parking in the same transaction, or
 * parks it again when that fails as often as an event may.
 */
#[Experimental]
final readonly class ReleaseParked
{
    public function __construct(
        private SubscriptionLog $log,
        private LaneSubscribers $subscribers,
    ) {}

    /**
     * @throws ReleaseRefused when no registered subscriber has the subscription, or the aggregate is not parked for it
     */
    public function release(ParkedRelease $release): ParkedAggregate
    {
        if (! $this->subscribers->named($release->subscription) instanceof SubscriberEntry) {
            throw ReleaseRefused::unknown($release->subscription);
        }

        return $this->log->release($release->subscription, $release->aggregate)
            ?? throw ReleaseRefused::notParked($release->subscription, $release->aggregate);
    }
}

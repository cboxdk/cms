<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedFilter;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;

/**
 * The aggregates that subscriptions have parked (PRD 7.8), by subscription and oldest first, so
 * an operator sees what waits for a fix and a release.
 */
#[Experimental]
final readonly class ListParked
{
    public function __construct(private SubscriptionLog $log) {}

    /**
     * @return list<ParkedAggregate>
     */
    public function list(ParkedFilter $filter): array
    {
        return $this->log->parked($filter->subscription);
    }
}

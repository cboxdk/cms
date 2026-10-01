<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;

/**
 * What a run of a lane's runner did: the service actor it ran as, the committed batches, the
 * events it handed to subscribers, those it passed, the failed tries, the aggregates it parked,
 * the released aggregates it handled once and those it found no event for, and the batches it
 * left to another runner that held the subscription's lock, and the subscriptions it refused to
 * run because they had no actor they may run as.
 */
#[Experimental]
final readonly class LaneReport
{
    /**
     * @param  list<Parking>  $parked
     * @param  list<AggregateKey>  $releasedWithoutEvent
     * @param  list<RefusedSubscription>  $refused
     */
    public function __construct(
        public Lane $lane,
        public ActorId $actor,
        public int $batches,
        public int $handled,
        public int $passed,
        public int $failures,
        public array $parked,
        public int $released,
        public array $releasedWithoutEvent,
        public int $busy,
        public array $refused = [],
    ) {}
}

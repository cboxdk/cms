<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;

/**
 * What one committed batch of a subscription did: the events it handed to the subscriber, those it
 * passed because the subscription does not receive their type or their aggregate is parked, the
 * aggregates it parked, the released aggregates it handled, those it found no event for and those
 * it parked again, and whether it did anything at all, so the runner knows to go on at once.
 */
#[Experimental]
final readonly class BatchProgress
{
    /**
     * @param  list<Parking>  $parked
     * @param  list<AggregateKey>  $releasedWithoutEvent
     */
    public function __construct(
        public int $handled = 0,
        public int $passed = 0,
        public int $passedParked = 0,
        public array $parked = [],
        public int $released = 0,
        public array $releasedWithoutEvent = [],
        public bool $moved = false,
    ) {}
}

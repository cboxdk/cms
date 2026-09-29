<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use DateTimeImmutable;

/**
 * An aggregate a subscription parked (PRD 7.8): the stream and position of the first event it
 * parked, the failed tries, when it was parked and, once an operator released it, when.
 */
#[Experimental]
final readonly class ParkedAggregate
{
    public function __construct(
        public SubscriptionName $subscription,
        public AggregateKey $aggregate,
        public EventStream $stream,
        public EventPosition $position,
        public int $attempts,
        public DateTimeImmutable $parkedAt,
        public ?DateTimeImmutable $releasedAt,
    ) {}

    public function isReleased(): bool
    {
        return $this->releasedAt instanceof DateTimeImmutable;
    }
}

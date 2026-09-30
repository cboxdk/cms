<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use DateTimeImmutable;

/**
 * A registered subscription and its oldest unhandled event (PRD 7.6, 7.12): the first event past
 * its cursor, in either stream, of a type it receives, committed or not yet below the transaction
 * horizon, or null when it has handled every event of its types.
 */
#[Internal]
final readonly class SubscriptionLag
{
    public function __construct(
        public SubscriptionName $subscription,
        public Lane $lane,
        public ?DateTimeImmutable $oldestUnhandled = null,
        public ?EventStream $stream = null,
    ) {}
}

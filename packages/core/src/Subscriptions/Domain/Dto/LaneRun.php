<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\Lane;

/**
 * One run of a lane's event runner: the lane, and whether it stops once the lane is idle, when no
 * subscription has an event to handle, a release or a try waiting, instead of running until it is
 * asked to stop.
 */
#[Experimental]
final readonly class LaneRun
{
    public function __construct(
        public Lane $lane,
        public bool $untilIdle = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A subscriber that lists an event class that does not exist.
 */
#[Subscription('fixture.missing', events: ['Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\NoSuchEvent'], lane: Lane::Standard)]
final readonly class MissingEventSubscriber implements Subscriber
{
    use IgnoresEvents;
}

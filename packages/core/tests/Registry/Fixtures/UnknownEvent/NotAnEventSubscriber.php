<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A subscriber that lists a class that is not an event.
 */
#[Subscription('fixture.not_an_event', events: [PlainValue::class], lane: Lane::Standard)]
final readonly class NotAnEventSubscriber implements Subscriber
{
    use IgnoresEvents;
}

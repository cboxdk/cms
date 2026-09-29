<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A subscriber that lists an event whose type() fails.
 */
#[Subscription('fixture.bad_type', events: [BadlyNamedEvent::class], lane: Lane::Standard)]
final readonly class BadTypeSubscriber implements Subscriber
{
    use IgnoresEvents;
}

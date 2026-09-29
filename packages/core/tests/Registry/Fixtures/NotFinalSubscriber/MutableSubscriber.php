<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalSubscriber;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * #[Subscription] on a final class that is not readonly, so it could keep state between events.
 */
#[Subscription('fixture.mutable', events: [NoteCreated::class], lane: Lane::Critical)]
final class MutableSubscriber implements Subscriber
{
    use IgnoresEvents;

    public int $handled = 0;
}

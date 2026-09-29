<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotASubscriber;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * #[Subscription] on a class that does not implement Subscriber.
 */
#[Subscription('fixture.plain', events: [NoteCreated::class], lane: Lane::Standard)]
final readonly class PlainListener
{
    use IgnoresEvents;
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalSubscriber;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * #[Subscription] on a readonly class that is not final.
 */
#[Subscription('fixture.open', events: [NoteCreated::class], lane: Lane::Critical)]
readonly class OpenSubscriber implements Subscriber
{
    use IgnoresEvents;
}

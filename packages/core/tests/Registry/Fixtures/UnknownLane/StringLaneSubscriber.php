<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownLane;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A subscriber that names its lane as a string, which is not a case of Lane.
 */
#[Subscription('fixture.string_lane', events: [NoteCreated::class], lane: 'urgent')]
final readonly class StringLaneSubscriber implements Subscriber
{
    use IgnoresEvents;
}

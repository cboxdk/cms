<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateSubscription;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * One of two subscribers that declare the same subscription name.
 */
#[Subscription('fixture.index', events: [NoteCreated::class], lane: Lane::Standard, projection: 'fixture_search')]
final readonly class FirstIndexer implements Subscriber
{
    use IgnoresEvents;
}

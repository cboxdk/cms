<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Addon;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * The fixture addon's subscriber on the standard lane, for the manifest tests.
 */
#[Subscription('fixture.reviews', events: [NoteCreated::class], lane: Lane::Standard)]
final readonly class IndexReviewedNote implements Subscriber
{
    use IgnoresEvents;
}

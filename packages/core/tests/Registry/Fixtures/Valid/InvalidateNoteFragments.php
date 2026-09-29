<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A fixture subscriber for the registry tests on the critical lane, which acknowledges the
 * fragments projection. It lists its events out of order.
 */
#[Subscription('fixture.fragments', events: [NoteCreated::class, NoteArchived::class], lane: Lane::Critical, projection: 'fixture_fragments')]
final readonly class InvalidateNoteFragments implements Subscriber
{
    use IgnoresEvents;
}

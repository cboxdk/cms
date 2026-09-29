<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A fixture subscriber for the registry tests on the standard lane, which acknowledges the search
 * projection.
 */
#[Subscription('fixture.search', events: [NoteCreated::class], lane: Lane::Standard, projection: 'fixture_search')]
final readonly class IndexNote implements Subscriber
{
    use IgnoresEvents;
}

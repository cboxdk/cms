<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\IgnoresEvents;

/**
 * A fixture subscriber for the registry tests on the external lane, which acknowledges no
 * projection.
 */
#[Subscription('fixture.webhooks', events: [NoteCreated::class, NoteRenamed::class], lane: Lane::External)]
final readonly class NotifyNoteWebhooks implements Subscriber
{
    use IgnoresEvents;
}

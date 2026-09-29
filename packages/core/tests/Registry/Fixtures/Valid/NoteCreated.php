<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoteEvent;

/**
 * A fixture event for the registry tests, which two subscriptions receive.
 */
final readonly class NoteCreated implements Event
{
    use NoteEvent;

    public static function type(): EventType
    {
        return new EventType('fixture.note_created', 1);
    }
}

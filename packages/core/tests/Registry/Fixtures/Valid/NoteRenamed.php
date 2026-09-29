<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoteEvent;

/**
 * A fixture event for the registry tests, which only a subscription without a projection receives.
 */
final readonly class NoteRenamed implements Event
{
    use NoteEvent;

    public static function type(): EventType
    {
        return new EventType('fixture.note_renamed', 1);
    }
}

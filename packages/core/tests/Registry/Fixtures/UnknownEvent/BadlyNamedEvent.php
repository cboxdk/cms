<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent;

use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoteEvent;

/**
 * An event whose type() throws, because its name has one segment.
 */
final readonly class BadlyNamedEvent implements Event
{
    use NoteEvent;

    public static function type(): EventType
    {
        return new EventType('renamed', 1);
    }
}

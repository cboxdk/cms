<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\EventAggregate;

/**
 * The aggregate and payload of a registry fixture's event, the first version of one note: the
 * registry tests read only the event's class and type.
 */
trait NoteEvent
{
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('note'), new NoteId('note-1'), 1);
    }

    public function payload(): NoPayload
    {
        return new NoPayload;
    }
}

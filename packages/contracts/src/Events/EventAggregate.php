<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\Identifier;

/**
 * The aggregate an event is about and the version the event's changeset left it at (PRD 7.2, 7.4).
 *
 * Subscribers are state-based: an event means "aggregate X is now at version V, read the state",
 * so a subscriber compares the version with the last one it handled for the aggregate and ignores
 * an older one. The version starts at 1 and rises with every changeset that changes the aggregate.
 */
#[Experimental]
final readonly class EventAggregate
{
    public EventIdentifier $id;

    public function __construct(
        public AggregateType $type,
        Identifier $id,
        public int $version,
    ) {
        if ($version < 1) {
            throw InvalidEvent::aggregateVersion($version);
        }

        $this->id = EventIdentifier::of($id);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;

/**
 * An event as the log holds it: the envelope of PRD 7.2 and the payload's data.
 *
 * generation is the failover generation, Postgres' timeline id when the event was written
 * (PRD 7.15): a receiver outside the database deduplicates on (generation, aggregate, version).
 */
#[Experimental]
final readonly class StoredEvent
{
    public function __construct(
        public EventPosition $position,
        public DateTimeImmutable $occurredAt,
        public ChangesetId $changesetId,
        public EventStream $stream,
        public int $generation,
        public EventAggregate $aggregate,
        public EventType $type,
        public EventData $data,
    ) {}
}

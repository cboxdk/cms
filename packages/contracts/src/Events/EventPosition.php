<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The place of an event in the log (PRD 7.4): the id of the transaction that wrote it, a
 * Postgres xid8, and its event_id. The log is read in this order, and a subscription's cursor in a
 * stream is the position of the last event it handled.
 *
 * The order is complete but is not commit order: a transaction with a lower event_id can commit
 * later. The log is therefore read only below the transaction horizon, where no transaction can
 * still commit, so reading after a cursor never skips an event.
 */
#[Experimental]
final readonly class EventPosition
{
    public function __construct(
        public int $xid,
        public int $eventId,
    ) {
        if ($xid < 0 || $eventId < 0) {
            throw InvalidEvent::position($xid, $eventId);
        }
    }

    /**
     * The position before every event, where a new subscription starts.
     */
    public static function start(): self
    {
        return new self(0, 0);
    }

    public function isAfter(self $other): bool
    {
        return $this->xid > $other->xid || ($this->xid === $other->xid && $this->eventId > $other->eventId);
    }

    public function equals(self $other): bool
    {
        return $this->xid === $other->xid && $this->eventId === $other->eventId;
    }
}

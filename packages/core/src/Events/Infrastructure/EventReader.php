<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Events\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Core\Events\Boundary\EventRows;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Reads the event log after a cursor (PRD 7.4), as the app role.
 *
 * Reading "the events with an id above my cursor" loses events: event_ids are handed out at
 * insert and transactions commit in another order, so a transaction with a lower event_id that
 * commits later would be skipped. The reader therefore returns only events of transactions that
 * have certainly ended, below the transaction horizon, `xid < pg_snapshot_xmin(pg_current_snapshot())`,
 * ordered by (xid, event_id). No transaction below the horizon can still commit, so a cursor moved
 * to the last event read never passes an event that turns up later. The order is complete but is
 * not commit order; subscribers are state-based and tolerate it (PRD 7.4).
 *
 * The horizon is shared by every transaction on the primary, so delivery is never faster than the
 * oldest open transaction there. Every read runs on the write PDO, the primary, also outside a
 * transaction, because a replica has its own horizon and may not have replayed an event yet
 * (PRD 7.4). The reader never begins a transaction; within a caller's REPEATABLE READ transaction
 * it reads that transaction's snapshot.
 */
#[Experimental]
final readonly class EventReader
{
    /** The most events one read returns. */
    public const int MAX_LIMIT = 10_000;

    private const string AFTER = <<<'SQL'
        select event_id, xid::text as xid,
            to_char(occurred_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as occurred_at,
            changeset_id::text as changeset_id, stream, generation, aggregate_type, aggregate_id,
            aggregate_version, type, type_version, data::text as data
        from events
        where stream = ?
            and (xid, event_id) > (?::xid8, ?::bigint)
            and xid < pg_snapshot_xmin(pg_current_snapshot())
        order by xid, event_id
        limit ?
        SQL;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * The first $limit events of the stream after the cursor, below the transaction horizon, in
     * (xid, event_id) order. EventPosition::start() reads from the first event.
     *
     * @return list<StoredEvent>
     */
    public function after(EventStream $stream, EventPosition $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('Read 1 to %d events at a time, not %d.', self::MAX_LIMIT, $limit));
        }

        $rows = $this->db()->select(
            self::AFTER,
            [$stream->value, (string) $cursor->xid, $cursor->eventId, $limit],
            false,
        );

        return EventRows::events($rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

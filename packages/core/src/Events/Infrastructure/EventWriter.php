<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Events\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Events\Boundary\EventDataJson;
use Cbox\Cms\Core\Events\Boundary\EventRows;
use Cbox\Cms\Core\Partitions\Boundary\MissingPartition;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * Writes the events of a changeset to the event log (PRD 7.2, 7.3), as the app role.
 *
 * It runs on the caller's connection, the default connection unless one is named, inside the
 * caller's open transaction, the command transaction, and never begins, commits or rolls back one
 * (GUARDRAILS 4.1, PRD 4.2): the events commit with the state they tell about, or neither does.
 * Without an open transaction it throws TransactionRequired before any statement, because an event
 * written in a transaction of its own would be in the log even when the change it tells about
 * rolled back.
 *
 * Each event gets its envelope here: the next event_id from the sequence, the transaction's id
 * (xid) and the failover generation from the column defaults, the Clock's time as occurred_at, and
 * the changeset and stream the caller gives. The type comes from the event's class, and the data
 * from its payload as EventDataJson. The events of one call are inserted in the order given, so
 * their event_ids rise in that order, up to CHUNK events per statement.
 *
 * An event outside the partitions throws PartitionMissing, and Postgres has then failed the
 * caller's transaction: the caller rolls back.
 */
#[Experimental]
final readonly class EventWriter
{
    /** The most events one insert statement carries, well below Postgres' 65535 bindings. */
    public const int CHUNK = 1000;

    private const string INSERT = <<<'SQL'
        insert into events (occurred_at, changeset_id, stream, aggregate_type, aggregate_id, aggregate_version, type, type_version, data)
        values %s
        returning event_id, xid::text as xid
        SQL;

    private const string ROW = '(?::timestamptz, ?::uuid, ?, ?, ?, ?, ?, ?, ?::jsonb)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection, the one
     *                                   the command kernel opens its transaction on
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    /**
     * Writes the events and returns their positions, in the order given.
     *
     * @param  list<Event>  $events
     * @return list<EventPosition>
     *
     * @throws TransactionRequired when the connection has no transaction open; nothing is written
     * @throws PartitionMissing when no partition of the stream covers the next event_id
     */
    public function write(ChangesetId $changesetId, EventStream $stream, array $events): array
    {
        $db = $this->db();

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forEvents();
        }

        $occurredAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $positions = [];

        foreach (array_chunk($events, self::CHUNK) as $chunk) {
            $bindings = [];

            foreach ($chunk as $event) {
                $aggregate = $event->aggregate();
                $type = $event::type();
                $bindings[] = $occurredAt;
                $bindings[] = $changesetId->toString();
                $bindings[] = $stream->value;
                $bindings[] = $aggregate->type->value;
                $bindings[] = $aggregate->id->value;
                $bindings[] = $aggregate->version;
                $bindings[] = $type->name;
                $bindings[] = $type->version;
                $bindings[] = EventDataJson::encode($event->payload()->data());
            }

            $sql = sprintf(self::INSERT, implode(', ', array_fill(0, count($chunk), self::ROW)));

            try {
                $rows = $db->select($sql, $bindings, false);
            } catch (QueryException $exception) {
                throw MissingPartition::of($exception) ?? $exception;
            }

            array_push($positions, ...EventRows::positions($rows));
        }

        return $positions;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

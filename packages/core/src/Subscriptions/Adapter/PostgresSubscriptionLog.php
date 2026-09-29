<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Events\Boundary\EventRows;
use Cbox\Cms\Core\Events\Infrastructure\EventReader;
use Cbox\Cms\Core\Subscriptions\Boundary\ParkedRows;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionTransactionOpen;
use Closure;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Throwable;

/**
 * The subscriptions' cursors and parked aggregates in Postgres (PRD 7.4 to 7.8), on the default
 * connection, or a named one, as the app role: the connection the subscribers write on, so their
 * writes commit with the cursor.
 *
 * A batch's transaction begins at READ COMMITTED and takes the transaction-scoped advisory lock
 * SubscriptionLock::of() with pg_try_advisory_xact_lock, so it never waits for another runner: when
 * the lock is taken it rolls back and gives null. Postgres releases the lock when the transaction
 * ends. Every read runs on the write PDO, the primary (PRD 7.4), and the events are read with the
 * EventReader, below the transaction horizon. The cursor is an upsert that never moves back. The
 * times it writes are the Clock's.
 */
#[Internal]
final readonly class PostgresSubscriptionLog implements SubscriptionLog
{
    public const string READ_COMMITTED = 'set transaction isolation level read committed';

    private const string LOCK = 'select pg_try_advisory_xact_lock(?) as locked';

    private const string CURSOR = 'select xid::text as xid, event_id from event_cursors where subscription = ? and stream = ?';

    private const string ADVANCE = <<<'SQL'
        insert into event_cursors (subscription, stream, xid, event_id, updated_at)
        values (?, ?, ?::xid8, ?, ?::timestamptz)
        on conflict (subscription, stream) do update
            set xid = excluded.xid, event_id = excluded.event_id, updated_at = excluded.updated_at
            where (event_cursors.xid, event_cursors.event_id) < (excluded.xid, excluded.event_id)
        SQL;

    private const string PARKED_AMONG = <<<'SQL'
        select p.aggregate_type, p.aggregate_id
        from event_parked_aggregates p
        join jsonb_to_recordset(?::jsonb) as k(type text, id text)
            on p.aggregate_type = k.type and p.aggregate_id = k.id
        where p.subscription = ?
        order by p.aggregate_type, p.aggregate_id
        SQL;

    private const string PARK = <<<'SQL'
        insert into event_parked_aggregates (subscription, aggregate_type, aggregate_id, stream, xid, event_id, attempts, parked_at)
        values (?, ?, ?, ?, ?::xid8, ?, ?, ?::timestamptz)
        on conflict (subscription, aggregate_type, aggregate_id) do nothing
        SQL;

    private const string PARKED_COLUMNS = <<<'SQL'
        subscription, aggregate_type, aggregate_id, stream, xid::text as xid, event_id, attempts,
        to_char(parked_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as parked_at,
        to_char(released_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as released_at
        SQL;

    private const string RELEASED = 'select %s from event_parked_aggregates where subscription = ? and released_at is not null order by parked_at, aggregate_type, aggregate_id limit ?';

    private const string NEWEST = <<<'SQL'
        select e.event_id, e.xid::text as xid,
            to_char(e.occurred_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as occurred_at,
            e.changeset_id::text as changeset_id, e.stream, e.generation, e.aggregate_type, e.aggregate_id,
            e.aggregate_version, e.type, e.type_version, e.data::text as data
        from events e
        join jsonb_to_recordset(?::jsonb) as t(name text, version integer)
            on e.type = t.name and e.type_version = t.version
        where e.aggregate_type = ? and e.aggregate_id = ?
            and exists (
                select 1 from event_cursors c
                where c.subscription = ? and c.stream = e.stream and (e.xid, e.event_id) <= (c.xid, c.event_id)
            )
        order by e.aggregate_version desc, e.xid desc, e.event_id desc
        limit 1
        SQL;

    private const string UNPARK = 'delete from event_parked_aggregates where subscription = ? and aggregate_type = ? and aggregate_id = ?';

    private const string REPARK = 'update event_parked_aggregates set released_at = null, attempts = attempts + ? where subscription = ? and aggregate_type = ? and aggregate_id = ?';

    private const string RELEASE = 'update event_parked_aggregates set released_at = coalesce(released_at, ?::timestamptz) where subscription = ? and aggregate_type = ? and aggregate_id = ? returning %s';

    private const string PARKED = 'select %s from event_parked_aggregates %s order by subscription, parked_at, aggregate_type, aggregate_id';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function transaction(SubscriptionName $subscription, Closure $work): ?BatchProgress
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $db = $this->connections->connection($name);

        if ($db->transactionLevel() > 0) {
            throw SubscriptionTransactionOpen::onConnection($name);
        }

        $db->beginTransaction();

        try {
            $db->statement(self::READ_COMMITTED);
            $lock = $db->selectOne(self::LOCK, [SubscriptionLock::of($subscription)], false);

            if (! is_object($lock) || ! property_exists($lock, 'locked') || $lock->locked !== true) {
                $db->rollBack();

                return null;
            }

            $progress = $work();
        } catch (Throwable $exception) {
            $db->rollBack();

            throw $exception;
        }

        try {
            $db->commit();
        } catch (Throwable $exception) {
            // A failed COMMIT ends the transaction on the server; this ends it on the connection.
            $db->rollBack();

            throw $exception;
        }

        return $progress;
    }

    #[Override]
    public function cursor(SubscriptionName $subscription, EventStream $stream): EventPosition
    {
        return ParkedRows::cursor($this->db()->selectOne(self::CURSOR, [$subscription->value, $stream->value], false));
    }

    #[Override]
    public function after(EventStream $stream, EventPosition $cursor, int $limit): array
    {
        return new EventReader($this->connections, $this->connection)->after($stream, $cursor, $limit);
    }

    #[Override]
    public function parkedAmong(SubscriptionName $subscription, array $aggregates): array
    {
        if ($aggregates === []) {
            return [];
        }

        $keys = json_encode(
            array_map(static fn (AggregateKey $aggregate): array => ['type' => $aggregate->type->value, 'id' => $aggregate->id->value], $aggregates),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return ParkedRows::aggregates($this->db()->select(self::PARKED_AMONG, [$keys, $subscription->value], false));
    }

    #[Override]
    public function advance(SubscriptionName $subscription, EventStream $stream, EventPosition $to): void
    {
        $this->db()->statement(self::ADVANCE, [$subscription->value, $stream->value, (string) $to->xid, $to->eventId, $this->now()]);
    }

    #[Override]
    public function park(SubscriptionName $subscription, StoredEvent $event, int $attempts): void
    {
        $this->db()->statement(self::PARK, [
            $subscription->value,
            $event->aggregate->type->value,
            $event->aggregate->id->value,
            $event->stream->value,
            (string) $event->position->xid,
            $event->position->eventId,
            $attempts,
            $this->now(),
        ]);
    }

    #[Override]
    public function released(SubscriptionName $subscription, int $limit): array
    {
        return ParkedRows::parked($this->db()->select(sprintf(self::RELEASED, self::PARKED_COLUMNS), [$subscription->value, $limit], false));
    }

    #[Override]
    public function newest(SubscriptionName $subscription, AggregateKey $aggregate, array $types): ?StoredEvent
    {
        if ($types === []) {
            return null;
        }

        $names = json_encode(
            array_map(static fn (EventType $type): array => ['name' => $type->name, 'version' => $type->version], $types),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $row = $this->db()->selectOne(self::NEWEST, [$names, $aggregate->type->value, $aggregate->id->value, $subscription->value], false);

        return $row === null ? null : EventRows::event($row);
    }

    #[Override]
    public function unpark(SubscriptionName $subscription, AggregateKey $aggregate): void
    {
        $this->db()->statement(self::UNPARK, [$subscription->value, $aggregate->type->value, $aggregate->id->value]);
    }

    #[Override]
    public function repark(SubscriptionName $subscription, AggregateKey $aggregate, int $attempts): void
    {
        $this->db()->statement(self::REPARK, [$attempts, $subscription->value, $aggregate->type->value, $aggregate->id->value]);
    }

    #[Override]
    public function release(SubscriptionName $subscription, AggregateKey $aggregate): ?ParkedAggregate
    {
        $row = $this->db()->selectOne(
            sprintf(self::RELEASE, self::PARKED_COLUMNS),
            [$this->now(), $subscription->value, $aggregate->type->value, $aggregate->id->value],
            false,
        );

        return $row === null ? null : ParkedRows::parking($row);
    }

    #[Override]
    public function parked(?SubscriptionName $subscription): array
    {
        if ($subscription instanceof SubscriptionName) {
            return ParkedRows::parked($this->db()->select(sprintf(self::PARKED, self::PARKED_COLUMNS, 'where subscription = ?'), [$subscription->value], false));
        }

        return ParkedRows::parked($this->db()->select(sprintf(self::PARKED, self::PARKED_COLUMNS, ''), [], false));
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}

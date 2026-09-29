<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The event log's tables (PRD 7.2 to 7.5, 7.8, 7.10), created by the owner role.
 *
 * `events` holds the envelope of PRD 7.2, one row per event, written in the command transaction
 * (PRD 7.3) by Cbox\Cms\Core\Events\Infrastructure\EventWriter:
 * - event_id comes from the sequence events_event_id_seq, in the order of insertion.
 * - xid is the writing transaction's id, pg_current_xact_id(), a column default so that no writer
 *   can give another. The reader reads only below the transaction horizon, in (xid, event_id)
 *   order, so an event whose transaction commits after one with a higher event_id is not skipped
 *   (PRD 7.4); the index events_position serves that read.
 * - generation is the failover generation (PRD 7.15), the timeline the primary writes WAL on: the
 *   first 8 hex digits of the name of the current WAL file, which pg_walfile_name() takes from the
 *   insertion timeline, so it is the new timeline from the first write after a promotion.
 *   pg_control_checkpoint() would only show it after the next checkpoint.
 * - type and type_version are the event class's EventType, and data the payload's EventData as
 *   JSON (Events\Boundary\EventDataJson): ids, versions, values that are not text and hashes of
 *   text, never content (PRD 6.5 invariant 10).
 *
 * The streams have their own partitions (PRD 7.5): `events` is partitioned by LIST on stream, and
 * each stream by RANGE on event_id, so the reader of one stream prunes to its own partitions. Both
 * streams share the one sequence and are managed by the partition manager as the tables
 * events_interactive and events_bulk (cbox-cms.database.partitions.tables), each with its runway
 * of partitions ahead of the sequence. A partition the sequence has passed is dropped when its
 * newest occurred_at is 30 days old (PRD 7.10); there is no DEFAULT partition, so an event outside
 * the partitions fails with PartitionMissing. The index events_occurred_at keeps the partition
 * manager's read of a partition's newest occurred_at cheap. A key on a partitioned table must hold
 * every partition key column, so the primary key is (stream, event_id). The sequence is owned by
 * event_id, so it goes with the table.
 *
 * `event_cursors` holds each subscription's cursor per stream (PRD 7.6, 7.15), the position of the
 * last event it handled, as (xid, event_id). It lives in the same database as the events, so a
 * subscriber commits its cursor in the transaction of its own writes.
 *
 * `event_parked_aggregates` holds the (subscription, aggregate) pairs that a subscription parked
 * after failed attempts (PRD 7.8), with the position of the event that was parked first; later
 * events of the aggregate are parked for that subscription too, until the row is removed and the
 * aggregate is handled once at its current version.
 *
 * The app role keeps SELECT and INSERT on events: nothing updates or deletes an event, whole
 * partitions are dropped. On event_cursors it keeps SELECT and INSERT, and UPDATE of the position
 * and updated_at alone, so a cursor cannot move to another subscription or stream. On
 * event_parked_aggregates it keeps the owner's default, SELECT, INSERT, UPDATE and DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('create sequence events_event_id_seq as bigint minvalue 1');
        $connection->statement(<<<'SQL'
            create table events (
                event_id bigint not null default nextval('events_event_id_seq'),
                xid xid8 not null default pg_current_xact_id(),
                occurred_at timestamptz not null,
                changeset_id uuid not null,
                stream text not null,
                generation bigint not null default (('x' || left(pg_walfile_name(pg_current_wal_lsn()), 8))::bit(32)::bigint),
                aggregate_type text not null,
                aggregate_id text not null,
                aggregate_version bigint not null,
                type text not null,
                type_version integer not null,
                data jsonb not null,
                constraint events_pkey primary key (stream, event_id),
                constraint events_stream check (stream in ('interactive', 'bulk')),
                constraint events_generation check (generation >= 1),
                constraint events_aggregate_type check (aggregate_type ~ '^[a-z][a-z0-9_]*$' and length(aggregate_type) <= 63),
                constraint events_aggregate_id check (aggregate_id ~ '^[!-~]{1,255}$'),
                constraint events_aggregate_version check (aggregate_version >= 1),
                constraint events_type check (type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$' and length(type) <= 63),
                constraint events_type_version check (type_version >= 1),
                constraint events_data check (jsonb_typeof(data) = 'object')
            ) partition by list (stream)
            SQL);
        $connection->statement("create table events_interactive partition of events for values in ('interactive') partition by range (event_id)");
        $connection->statement("create table events_bulk partition of events for values in ('bulk') partition by range (event_id)");
        $connection->statement('alter sequence events_event_id_seq owned by events.event_id');
        $connection->statement('create index events_position on events (xid, event_id)');
        $connection->statement('create index events_occurred_at on events (occurred_at)');

        $connection->statement(<<<'SQL'
            create table event_cursors (
                subscription text not null,
                stream text not null,
                xid xid8 not null,
                event_id bigint not null,
                updated_at timestamptz not null,
                constraint event_cursors_pkey primary key (subscription, stream),
                constraint event_cursors_subscription check (subscription ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$' and length(subscription) <= 63),
                constraint event_cursors_stream check (stream in ('interactive', 'bulk')),
                constraint event_cursors_event_id check (event_id >= 0)
            )
            SQL);

        $connection->statement(<<<'SQL'
            create table event_parked_aggregates (
                subscription text not null,
                aggregate_type text not null,
                aggregate_id text not null,
                stream text not null,
                xid xid8 not null,
                event_id bigint not null,
                attempts integer not null,
                parked_at timestamptz not null,
                constraint event_parked_aggregates_pkey primary key (subscription, aggregate_type, aggregate_id),
                constraint event_parked_aggregates_subscription check (subscription ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$' and length(subscription) <= 63),
                constraint event_parked_aggregates_aggregate_type check (aggregate_type ~ '^[a-z][a-z0-9_]*$' and length(aggregate_type) <= 63),
                constraint event_parked_aggregates_aggregate_id check (aggregate_id ~ '^[!-~]{1,255}$'),
                constraint event_parked_aggregates_stream check (stream in ('interactive', 'bulk')),
                constraint event_parked_aggregates_event_id check (event_id >= 1),
                constraint event_parked_aggregates_attempts check (attempts >= 1)
            )
            SQL);

        $privileges = new TablePrivileges($connection);
        $privileges->limitTo('events', [TablePrivilege::Select, TablePrivilege::Insert]);
        $privileges->limitTo('event_cursors', [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);
        $privileges->limitColumns('event_cursors', TablePrivilege::Update, ['xid', 'event_id', 'updated_at']);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop table event_parked_aggregates');
        $connection->statement('drop table event_cursors');
        $connection->statement('drop table events');
    }
};

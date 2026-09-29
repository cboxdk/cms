<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What the event runner needs of the event log's tables to release a parked aggregate (PRD 7.8),
 * created by the owner role.
 *
 * event_parked_aggregates.released_at is when an operator released the parking (cms:events:release).
 * The runner of the subscription's lane then hands the subscriber, once, the newest event of the
 * aggregate that the subscription has passed, and removes the row in the same transaction; a
 * release that fails as often as an event may clears released_at again. A row without it is parked.
 *
 * The index events_aggregate finds that event, the one with the highest aggregate_version of the
 * aggregate, without reading every partition of the stream in full.
 *
 * The app role keeps its privileges on event_parked_aggregates, SELECT, INSERT, UPDATE and DELETE,
 * which cover the new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('alter table event_parked_aggregates add column released_at timestamptz');
        $connection->statement('create index events_aggregate on events (aggregate_type, aggregate_id, aggregate_version)');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop index events_aggregate');
        $connection->statement('alter table event_parked_aggregates drop column released_at');
    }
};

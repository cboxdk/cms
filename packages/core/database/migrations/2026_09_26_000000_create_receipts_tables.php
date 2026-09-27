<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The receipt store's tables (PRD 4, 4.1, 8.4), created by the owner role.
 *
 * `receipts` holds one row per changeset: the changeset and its retention class. It holds no
 * outcome and no wait level: the store writes the row in the command transaction (PRD 6.2 phase
 * 7), before any wait level past commit can be reached, and whether a call reached its wait level
 * belongs to that call and to each replay (PRD 6.1, 8.4). `receipt_projections` holds one row per
 * changeset and projection, so a projection worker that marks its projection updates its own row
 * and never waits for another worker's.
 *
 * Both are partitioned by LIST on retention_class and then by RANGE on changeset_id, a UUIDv7
 * whose first 48 bits are the commit's milliseconds. The standard branch has one partition per
 * day and is dropped a week after the day ends. The evidence branch has one partition per month
 * and is never dropped by the partition manager; a policy decides when evidence goes. The
 * partitions come from `cms:partitions:maintain` (cms.database.partitions.tables); there is no
 * DEFAULT partition, so a write outside them fails with PartitionMissing.
 *
 * A unique key on a partitioned table must contain every partition key column, so the primary
 * key is (changeset_id, retention_class). There is no foreign key from receipt_projections to
 * receipts: the store writes both in the caller's transaction, and the partitions of both are
 * dropped on the same schedule.
 *
 * The app role keeps only the DML the store needs: SELECT and INSERT on receipts, and SELECT,
 * INSERT and UPDATE on receipt_projections. Nothing deletes rows; whole partitions are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            create table receipts (
                changeset_id uuid not null,
                retention_class text not null,
                constraint receipts_pkey primary key (changeset_id, retention_class)
            ) partition by list (retention_class)
            SQL);
        $connection->statement("create table receipts_standard partition of receipts for values in ('standard') partition by range (changeset_id)");
        $connection->statement("create table receipts_evidence partition of receipts for values in ('evidence') partition by range (changeset_id)");

        $connection->statement(<<<'SQL'
            create table receipt_projections (
                changeset_id uuid not null,
                retention_class text not null,
                projection text not null,
                state text not null,
                acknowledged_at timestamptz,
                constraint receipt_projections_pkey primary key (changeset_id, retention_class, projection),
                constraint receipt_projections_state check (state in ('pending', 'acknowledged')),
                constraint receipt_projections_acknowledged_at check ((state = 'acknowledged') = (acknowledged_at is not null))
            ) partition by list (retention_class)
            SQL);
        $connection->statement("create table receipt_projections_standard partition of receipt_projections for values in ('standard') partition by range (changeset_id)");
        $connection->statement("create table receipt_projections_evidence partition of receipt_projections for values in ('evidence') partition by range (changeset_id)");

        $privileges = new TablePrivileges($connection);
        $privileges->limitTo('receipts', [TablePrivilege::Select, TablePrivilege::Insert]);
        $privileges->limitTo('receipt_projections', [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop table receipt_projections');
        $connection->statement('drop table receipts');
    }
};

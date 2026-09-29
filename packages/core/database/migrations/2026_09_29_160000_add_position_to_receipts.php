<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The commit position on the receipt (PRD 7.4, 8.4, 8.12), added by the owner role.
 *
 * `receipts.position` is the xid8 of the command transaction that stored the receipt, the value
 * pg_current_xact_id() gives in it and the one the changeset row (`changesets.xid`) and its events
 * (`events.xid`) carry. A read whose snapshot xmin is above it saw the changeset. The store writes
 * the value it is given after it has checked that it is its transaction's, so the column has no
 * default: a default would hide a caller that forgot it.
 *
 * A column added to a partitioned table is added to every partition, the ones the partition
 * manager creates later included. The app role's table-wide SELECT and INSERT on `receipts` cover
 * it; it gets no UPDATE, so a stored position never changes. No receipt exists before this
 * migration runs, so the column is NOT NULL from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->statement('alter table receipts add column position xid8 not null');
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('alter table receipts drop column position');
    }
};

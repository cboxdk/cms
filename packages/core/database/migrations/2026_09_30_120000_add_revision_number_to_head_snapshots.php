<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The number of the revision a head snapshot holds (PRD 4.1, 5.4), added by the owner role.
 *
 * A type whose history is audit-only or none keeps no revisions, only the head snapshot, so the
 * head's draft_revision_id stays null and the number of its current revision lives with the
 * snapshot: `head_snapshots.rev_no`, 1 for the snapshot a create writes and one higher for each
 * revise, as rev_no counts in `revisions` for a type with full history. entry.revise reads it to
 * number the next revision, and the head's move checks that the snapshot holds the revision it
 * moves to. The writer always gives it, so the column has no default: a default would hide a
 * writer that forgot it. No snapshot is written before this migration runs, so the column is NOT
 * NULL from the start. It is not indexed, because every save changes it in place (PRD 4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->statement(
            'alter table head_snapshots add column rev_no integer not null, add constraint head_snapshots_rev_no check (rev_no >= 1)',
        );
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('alter table head_snapshots drop column rev_no');
    }
};

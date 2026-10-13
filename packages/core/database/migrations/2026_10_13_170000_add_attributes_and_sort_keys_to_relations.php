<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The attributes and the sort key of a relation's edges (PRD 5.11), created by the owner role.
 *
 * `meta` holds the attributes the edge's target carries, such as a role on a byline, as the
 * blueprint declares them per relation field: a small JSON object of the attributes by handle, in
 * the input form of their values, or null for a relation without attributes. The entry commands
 * write it with the edge, from the revision.
 *
 * `filter_key` is the text of the relation's filterable attribute on the edge (a relation has at
 * most one), and `sort_key` the value of a filterable relation's sort_by field, a sortable field of
 * the source's owner, which the kernel writes onto the released edges at release, in the same
 * transaction, as text that sorts byte by byte as the values do (the core's RelationSortKey), or
 * null when the source has no value. Both are text in the C collation, because the comparisons of
 * text are leakproof and those of jsonb are not: with row level security forced on `relations`,
 * Postgres seeks an index only on leakproof conditions. `relations_sorted` on (target, field, sort
 * key, source) over the released edges makes "the 20 newest sources of one target" an index scan
 * of 20 rows, read backwards from the cursor, whatever the number of sources: null sorts as the
 * greatest value, as in the type tables. A filterable attribute has a partial index of its own on
 * (target, filter key, sort key, source) for its field's released edges, which the type table
 * migration of the relation's type creates on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            alter table relations
                add column meta jsonb,
                add column filter_key text collate "C",
                add column sort_key text collate "C",
                add constraint relations_meta check (meta is null or jsonb_typeof(meta) = 'object')
            SQL);
        $connection->statement("create index relations_sorted on relations (target_entry_id, field, sort_key, source_entry_id) where stage = 'released'");
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop index relations_sorted');
        $connection->statement('alter table relations drop constraint relations_meta, drop column sort_key, drop column filter_key, drop column meta');
    }
};

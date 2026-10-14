<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The node targets of the relations (PRD 5.11), created by the owner role: a link field (B2-T76)
 * names an entry, a node of the structure or a URL, and a link to an entry or a node is an edge
 * of `relations` as a relation's target is, so "where is this node linked from" is one index read.
 *
 * An edge now has exactly one target: an entry in `target_entry_id`, or a node in the new
 * `target_node_id` (`relations_one_target`). The node is a real foreign key to `nodes` with ON
 * DELETE RESTRICT, as the entry is, and `relations_target_node` on (target node, field) is the
 * index that leads with it (PRD 4.1). A link to an entry in a locale keeps the locale in the
 * edge's `meta`. A link to a URL is no edge.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            alter table relations
                alter column target_entry_id drop not null,
                add column target_node_id uuid,
                add constraint relations_target_node_fkey foreign key (target_node_id) references nodes (id) on delete restrict,
                add constraint relations_one_target check (num_nonnulls(target_entry_id, target_node_id) = 1)
            SQL);
        $connection->statement('create index relations_target_node on relations (target_node_id, field)');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop index relations_target_node');
        $connection->statement('delete from relations where target_entry_id is null');
        $connection->statement('alter table relations drop constraint relations_one_target, drop constraint relations_target_node_fkey, drop column target_node_id, alter column target_entry_id set not null');
    }
};

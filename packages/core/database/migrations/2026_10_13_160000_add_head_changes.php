<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Who changed a variant's head last, and when (PRD 5.4, 6.1): `variant_heads.changed_by_actor_id`
 * is the actor of the changeset that last gave the head its version, and `changed_at` the time of
 * that changeset, so a reader of entry.get sees who saved a variant it is editing and an editor
 * whose save was refused with version_conflict can say who saved in between. Every writer that
 * sets a head's version sets both in the same statement: the first revision, a revise, a release,
 * an unrelease and a withdrawal.
 *
 * Both are null for a head no command has changed since this migration, such as a head an earlier
 * version wrote, and set together otherwise (`variant_heads_changed`). The actor is a foreign key
 * to `actors` with its own index (PRD 4.1). The column holds an id and no profile: entry.get reads
 * the actor's display name through `actor_profiles` under the reader's actor context, so its row
 * level security decides whether the reader sees the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            alter table variant_heads
                add column changed_by_actor_id uuid references actors (id),
                add column changed_at timestamptz,
                add constraint variant_heads_changed check ((changed_by_actor_id is null) = (changed_at is null))
            SQL);
        $connection->statement('create index variant_heads_changed_by_actor_id on variant_heads (changed_by_actor_id)');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop index variant_heads_changed_by_actor_id');
        $connection->statement('alter table variant_heads drop constraint variant_heads_changed, drop column changed_at, drop column changed_by_actor_id');
    }
};

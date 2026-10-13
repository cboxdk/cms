<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The relations (PRD 5.11), created by the owner role: the current state of what the variant heads
 * reference through their relation fields, an edge per target.
 *
 * `relations` has one row per edge: the source entry and its variant, the stage (`draft`, the
 * head's draft, or `released`, its released revision; a type with stages none has only the
 * released stage), the field by its address (the owner's handle, `ext.<namespace>.<handle>` for an
 * extender's, and a nested field's handle after its group's, `<group>.<handle>`, without the items'
 * indexes), the ordinal of the target among the field's targets in the variant and stage, in the
 * order the revision holds them, counted from 0, and the target entry. The entry commands write a
 * variant's edges in the transaction that writes its type row; a release writes the released
 * edges, an unrelease removes them, and deleting a language's variant removes all of its edges.
 *
 * The target is a real foreign key to `entries` with ON DELETE RESTRICT: content is tombstoned
 * long before it is removed, so the key is the physical backstop, and the kernel enforces the
 * logical policy. The source names its variant head, so an edge names a variant that exists. Each
 * foreign key has an index that leads with its columns (PRD 4.1): the primary key for the head,
 * and `relations_target` on (target, field) for the target, which also answers where an entry is
 * used. Historical revisions keep their targets as ids without a key, in their payloads.
 *
 * Row level security is enabled and forced. The app role reads and writes the edges of an entry
 * under an actor context whose regions reach the entry's home or that owns the entry
 * (`relations_actor`, over `cms_access_entry`, as the revisions), and every context reads the
 * released edges of an entry with a live released placement (`relations_released`, over
 * `cms_access_released`, as the type tables). Without a context no row is read.
 */
return new class extends Migration
{
    /** A locale: a BCP 47 language with optional subtags, as the variant heads have it. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    /** A field's address: handles of lowercase letters, digits and underscores, joined by dots. */
    private const string FIELD = '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;
        $field = self::FIELD;

        $connection->statement(<<<SQL
            create table relations (
                source_entry_id uuid not null,
                variant text not null,
                stage text not null,
                field text not null,
                ordinal integer not null,
                target_entry_id uuid not null,
                constraint relations_pkey primary key (source_entry_id, variant, stage, field, ordinal),
                constraint relations_head_fkey foreign key (source_entry_id, variant) references variant_heads (entry_id, variant),
                constraint relations_target_fkey foreign key (target_entry_id) references entries (id) on delete restrict,
                constraint relations_variant check (variant = 'shared' or variant ~ '{$locale}'),
                constraint relations_stage check (stage in ('draft', 'released')),
                constraint relations_field check (field ~ '{$field}' and octet_length(field) <= 255),
                constraint relations_ordinal check (ordinal >= 0)
            )
            SQL);
        $connection->statement('create index relations_target on relations (target_entry_id, field)');
        $connection->statement('alter table relations enable row level security');
        $connection->statement('alter table relations force row level security');
        $connection->statement('create policy relations_actor on relations for all using (cms_access_entry(source_entry_id)) with check (cms_access_entry(source_entry_id))');
        $connection->statement('create policy relations_released on relations for select using (cms_access_released(source_entry_id, stage))');
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table relations');
    }
};

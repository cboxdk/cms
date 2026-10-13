<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The derivations (PRD 4, 9.2, 9.5, 10.3), created by the owner role: the edges from a revision to
 * the revision it was derived from. variant.create_from writes the first, translated_from, when it
 * creates a language's variant of an entry from a revision of another of its variants.
 *
 * `derivations` has one row per derived revision and kind: the changeset that wrote it, the entry,
 * the derived variant and the number of its revision, the kind, the source variant of the same
 * entry, another language's, and the number of its revision, the time, and `stale_changeset_id`, the changeset of the
 * release of the source that made the derivation stale, or null while it is current. A release of
 * the source variant whose content is neither the source revision nor its published copy marks
 * every derivation from that variant stale that is not stale already, in the release's changeset,
 * so a translation tells its translator that the text it was made from has changed (PRD 10.3).
 * The heads of both variants are foreign keys, so a derivation names variants that exist, each with
 * an index that leads with its columns (PRD 4.1).
 *
 * Derivations are historical and partitioned by RANGE on changeset_id, managed per month by the
 * partition manager and kept until a retention policy comes (PRD 4, 9.6). The primary key holds the
 * partition key, as Postgres requires.
 *
 * Row level security is enabled and forced. The app role reads and writes a derivation under an
 * actor context whose regions reach the entry's home or that owns the entry (`derivations_actor`,
 * over `cms_access_entry`, as the revisions), and no context reads one without that. It has SELECT
 * and INSERT, and UPDATE of stale_changeset_id alone, so a derivation is never rewritten once
 * written, only marked stale.
 */
return new class extends Migration
{
    /** A locale: a BCP 47 language with optional subtags, as the variant heads have it. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;

        $connection->statement(<<<SQL
            create table derivations (
                changeset_id uuid not null references changeset_register (changeset_id),
                entry_id uuid not null,
                variant text not null,
                rev_no integer not null,
                kind text not null,
                source_variant text not null,
                source_rev_no integer not null,
                stale_changeset_id uuid,
                created_at timestamptz not null,
                constraint derivations_pkey primary key (changeset_id, entry_id, variant, kind),
                constraint derivations_head_fkey foreign key (entry_id, variant) references variant_heads (entry_id, variant),
                constraint derivations_source_fkey foreign key (entry_id, source_variant) references variant_heads (entry_id, variant),
                constraint derivations_kind check (kind in ('translated_from')),
                constraint derivations_variant check (variant ~ '{$locale}'),
                constraint derivations_source_variant check (source_variant ~ '{$locale}'),
                constraint derivations_other_variant check (source_variant <> variant),
                constraint derivations_rev_no check (rev_no >= 1 and source_rev_no >= 1)
            ) partition by range (changeset_id)
            SQL);
        $connection->statement('create index derivations_head on derivations (entry_id, variant)');
        $connection->statement('create index derivations_source on derivations (entry_id, source_variant)');
        $connection->statement('alter table derivations enable row level security');
        $connection->statement('alter table derivations force row level security');
        $connection->statement('create policy derivations_actor on derivations for all using (cms_access_entry(entry_id)) with check (cms_access_entry(entry_id))');

        $privileges = new TablePrivileges($connection);
        $privileges->limitTo('derivations', [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);
        $privileges->limitColumns('derivations', TablePrivilege::Update, ['stale_changeset_id']);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table derivations');
    }
};

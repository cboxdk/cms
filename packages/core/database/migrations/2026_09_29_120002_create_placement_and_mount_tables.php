<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The storage form of PRD 4.1 for placements and mount overrides (PRD 5.7 to 5.9), created by the
 * owner role. None of these tables is partitioned.
 *
 * `placements` is the stable identity of a placement: the entry it places, never changed, and a
 * version for optimistic concurrency. Curations, redirects and canonicalisation point at it, and a
 * release bundle never deletes one that is referenced (PRD 4.1). An entry may have many
 * placements, several in one site too (PRD 5.7).
 *
 * `placement_generations` holds what can change per stage (PRD 4.1): one row per placement and
 * stage, `released`, `draft` (only where a pending draft differs) or `staged` (prepared by a
 * release bundle, PRD 18.1), with the node the placement sits under. Moving a placement changes its
 * node and keeps its identity (PRD 5.7).
 *
 * `placement_locales` is the PlacementLocale of PRD 5.7 per stage: the slug, the visibility state
 * of PRD 6.4 with its window `live_from` and `live_until`, the time of the next scheduled
 * transition (PRD 6.7) and the canonical flag. It repeats the placement's entry and its
 * generation's node, held to them by composite foreign keys (the node follows a move through ON
 * UPDATE CASCADE), so the two rules of PRD 6.5 are partial unique indexes on one table:
 * `placement_locales_slug_key` keeps (node, locale, slug) unique among the placements that are not
 * withdrawn (invariant 15, the lookup of PRD 5.9 step 3), and `placement_locales_canonical_key`
 * allows at most one canonical placement per (entry, locale) (invariant 14), both per stage (PRD
 * 4.1). A withdrawn placement is never canonical. That exactly one is canonical once one is visible
 * is a rule over several rows, which the commands keep.
 *
 * A mount is a node of kind `mount` with its source node (`nodes.mount_source_id`); it shows the
 * source's placements and creates no rows (PRD 5.8). `mount_overrides` holds the exceptions of a
 * site to a mount, per mount node and entry: `hidden` and an optional `priority_cap`, at least one of
 * them set. Its foreign key to `nodes (id, mount_source_id)` accepts only a mount node and carries
 * the mount's source, which follows a change of the source through ON UPDATE CASCADE.
 *
 * The placement tables have fillfactor 80 and a low vacuum threshold (PRD 4.2), and no index on a
 * column a save changes other than the predicates of the two unique rules. Every table has row
 * level security, forced so it holds for the owner too, and no policy yet, so it is closed to every
 * role but a superuser until the policies over the actor context come (PRD 5.10). The app role gets
 * SELECT, INSERT and UPDATE on `placements`, whose identity is never deleted, and DELETE as well on
 * the generations, the locales and the overrides, because a draft generation is removed once it no
 * longer differs and an override can be lifted.
 */
return new class extends Migration
{
    /** @var list<string> in the order the tables are created */
    private const array TABLES = ['placements', 'placement_generations', 'placement_locales', 'mount_overrides'];

    /** @var list<string> the tables whose rows the application never deletes */
    private const array KEPT = ['placements'];

    /** A locale: a BCP 47 language with optional subtags, as in `site_locales`. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;

        $connection->statement('alter table nodes add constraint nodes_mount_key unique (id, mount_source_id)');

        $connection->statement(<<<'SQL'
            create table placements (
                id uuid primary key,
                entry_id uuid not null references entries (id),
                version bigint not null,
                created_at timestamptz not null,
                constraint placements_entry_key unique (entry_id, id),
                constraint placements_version check (version >= 1)
            ) with (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.01)
            SQL);

        $connection->statement(<<<'SQL'
            create table placement_generations (
                placement_id uuid not null references placements (id),
                stage text not null,
                node_id uuid not null references nodes (id),
                created_at timestamptz not null,
                primary key (placement_id, stage),
                constraint placement_generations_node_key unique (placement_id, stage, node_id),
                constraint placement_generations_stage check (stage in ('released', 'draft', 'staged'))
            ) with (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.01)
            SQL);
        $connection->statement('create index placement_generations_node_id on placement_generations (node_id)');

        $connection->statement(<<<SQL
            create table placement_locales (
                placement_id uuid not null,
                stage text not null,
                locale text not null,
                entry_id uuid not null,
                node_id uuid not null,
                slug text not null,
                visibility text not null,
                live_from timestamptz,
                live_until timestamptz,
                next_transition_at timestamptz,
                canonical boolean not null,
                created_at timestamptz not null,
                primary key (placement_id, stage, locale),
                constraint placement_locales_placement_fkey foreign key (entry_id, placement_id) references placements (entry_id, id),
                constraint placement_locales_generation_fkey foreign key (placement_id, stage, node_id)
                    references placement_generations (placement_id, stage, node_id) on update cascade,
                constraint placement_locales_locale check (locale ~ '{$locale}'),
                constraint placement_locales_slug check (slug ~ '^[^/[:space:]]+$' and slug not in ('.', '..')),
                constraint placement_locales_visibility check (visibility in ('hidden', 'scheduled', 'live', 'expired', 'withdrawn')),
                constraint placement_locales_window check (live_until > live_from),
                constraint placement_locales_scheduled check (visibility <> 'scheduled' or live_from is not null),
                constraint placement_locales_expired check (visibility <> 'expired' or live_until is not null),
                constraint placement_locales_canonical check (not canonical or visibility <> 'withdrawn')
            ) with (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.01)
            SQL);
        $connection->statement('create index placement_locales_entry on placement_locales (entry_id, placement_id)');
        $connection->statement('create index placement_locales_generation on placement_locales (placement_id, stage, node_id)');
        $connection->statement(<<<'SQL'
            create unique index placement_locales_slug_key on placement_locales (node_id, locale, slug, stage)
                where visibility <> 'withdrawn'
            SQL);
        $connection->statement(<<<'SQL'
            create unique index placement_locales_canonical_key on placement_locales (entry_id, locale, stage)
                where canonical
            SQL);

        $connection->statement(<<<'SQL'
            create table mount_overrides (
                mount_node_id uuid not null,
                source_node_id uuid not null,
                entry_id uuid not null references entries (id),
                hidden boolean not null,
                priority_cap integer,
                version bigint not null,
                created_at timestamptz not null,
                primary key (mount_node_id, entry_id),
                constraint mount_overrides_mount_fkey foreign key (mount_node_id, source_node_id)
                    references nodes (id, mount_source_id) on update cascade,
                constraint mount_overrides_effect check (hidden or priority_cap is not null),
                constraint mount_overrides_version check (version >= 1)
            )
            SQL);
        $connection->statement('create index mount_overrides_mount on mount_overrides (mount_node_id, source_node_id)');
        $connection->statement('create index mount_overrides_entry_id on mount_overrides (entry_id)');

        $privileges = new TablePrivileges($connection);

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
            $privileges->limitTo($table, in_array($table, self::KEPT, true)
                ? [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]
                : [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update, TablePrivilege::Delete]);
        }
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $connection->statement('drop table '.implode(', ', array_reverse(self::TABLES)));
        $connection->statement('alter table nodes drop constraint nodes_mount_key');
    }
};

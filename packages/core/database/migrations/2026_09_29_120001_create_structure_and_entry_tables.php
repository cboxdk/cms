<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The storage form of PRD 4.1 for the structure and the entries (PRD 5.2 to 5.8), created by the
 * owner role. None of these tables is partitioned (PRD 4).
 *
 * `nodes` is the one tree every site shares: site roots, sections, pages, lists, storage folders
 * and mounts, never articles (PRD 5.8). Each node's `path` is an ltree whose labels are the ids of
 * the node's ancestors and its own, as 32 hex digits, so a node's last label is its own id and a
 * rename changes no path; moving a section rewrites the paths below it. A GiST index on `path`
 * serves the subtree tests of the grants and of row level security (PRD 5.10). A mount names the
 * node whose placements it shows (`mount_source_id`).
 *
 * `sites` names a site and its root node, and `site_locales` the locales it is published in.
 * `node_routes` holds the route of a node per site and locale, for the longest-prefix lookup of
 * PRD 5.9: the candidate prefixes of a path are looked up in the primary key (site, locale, route)
 * and the longest found wins. A route is `/` or `/`-separated segments without a trailing slash,
 * and carries a locale of its site, which the foreign key to `site_locales` enforces.
 *
 * `entries` is identity alone (PRD 5.4): its type, its home node, the actor that owns it when its
 * type declares ownership (PRD 5.15, null otherwise), its lifecycle state (PRD 6.4) and a version
 * for optimistic concurrency. `variant_heads` is the head of each variant (PRD 5.4): the variant is
 * `shared` or a locale, and a type that is not localized has the one variant `shared`. The head
 * holds the draft and the published revision, the schema version the current state is written in,
 * the release state (PRD 6.4), the workflow state a workflow configures on top of it, the time of
 * the next scheduled transition (PRD 6.7) and a version. The revision ids are the bigint of the
 * revision register, whose foreign keys come with that register, each with the index on the
 * referencing side that PRD 4.1 requires, the draft revision's too. It has fillfactor 80 and a low
 * vacuum threshold, because every save updates it in place (PRD 4.2), and no other index on a
 * column a save changes.
 *
 * Every table has row level security, forced so it holds for the owner too (PRD 4.2), and no policy
 * yet, so it is closed to every role but a superuser until the policies over the actor context come
 * (PRD 5.10). The app role's grants are narrowed to SELECT, INSERT and UPDATE; nothing here is
 * deleted by the application.
 */
return new class extends Migration
{
    /** @var list<string> in the order the tables are created */
    private const array TABLES = ['nodes', 'sites', 'site_locales', 'node_routes', 'entries', 'variant_heads'];

    /** A locale: a BCP 47 language with optional subtags, such as da, en-GB or zh-Hant-TW. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;

        $connection->statement(<<<'SQL'
            create table nodes (
                id uuid primary key,
                parent_id uuid references nodes (id),
                kind text not null,
                path ltree not null,
                mount_source_id uuid references nodes (id),
                version bigint not null,
                created_at timestamptz not null,
                constraint nodes_kind check (kind in ('site', 'section', 'page', 'list', 'storage', 'mount')),
                constraint nodes_path_label check (
                    case when nlevel(path) >= 1
                        then ltree2text(subpath(path, nlevel(path) - 1, 1)) = replace(id::text, '-', '')
                        else false
                    end
                ),
                constraint nodes_parent check (parent_id <> id),
                constraint nodes_root check ((parent_id is null) = (nlevel(path) = 1)),
                constraint nodes_mount check ((kind = 'mount') = (mount_source_id is not null)),
                constraint nodes_mount_source check (mount_source_id <> id),
                constraint nodes_version check (version >= 1)
            )
            SQL);
        $connection->statement('create index nodes_parent_id on nodes (parent_id)');
        $connection->statement('create index nodes_mount_source_id on nodes (mount_source_id)');
        $connection->statement('create index nodes_path on nodes using gist (path)');

        $connection->statement(<<<'SQL'
            create table sites (
                id uuid primary key,
                handle text not null,
                root_node_id uuid not null references nodes (id),
                version bigint not null,
                created_at timestamptz not null,
                constraint sites_handle_key unique (handle),
                constraint sites_root_node_id_key unique (root_node_id),
                constraint sites_handle check (handle ~ '^[a-z][a-z0-9_]{0,62}$'),
                constraint sites_version check (version >= 1)
            )
            SQL);

        $connection->statement(<<<SQL
            create table site_locales (
                site_id uuid not null references sites (id),
                locale text not null,
                created_at timestamptz not null,
                primary key (site_id, locale),
                constraint site_locales_locale check (locale ~ '{$locale}')
            )
            SQL);

        $connection->statement(<<<'SQL'
            create table node_routes (
                site_id uuid not null,
                locale text not null,
                route text not null,
                node_id uuid not null references nodes (id),
                created_at timestamptz not null,
                primary key (site_id, locale, route),
                constraint node_routes_site_locale_fkey foreign key (site_id, locale) references site_locales (site_id, locale),
                constraint node_routes_node_key unique (node_id, site_id, locale),
                constraint node_routes_route check (route ~ '^(/|(/[^/[:space:]]+)+)$')
            )
            SQL);

        $connection->statement(<<<'SQL'
            create table entries (
                id uuid primary key,
                type_id uuid not null,
                home_node_id uuid not null references nodes (id),
                owner_actor_id uuid references actors (id),
                lifecycle text not null,
                version bigint not null,
                created_at timestamptz not null,
                constraint entries_lifecycle check (lifecycle in ('active', 'archived', 'merged', 'tombstoned', 'purged')),
                constraint entries_version check (version >= 1)
            )
            SQL);
        $connection->statement('create index entries_home_node_id on entries (home_node_id)');
        $connection->statement('create index entries_owner_actor_id on entries (owner_actor_id)');

        $connection->statement(<<<SQL
            create table variant_heads (
                entry_id uuid not null references entries (id),
                variant text not null,
                draft_revision_id bigint,
                published_revision_id bigint,
                schema_version integer not null,
                release_state text not null,
                workflow_state text,
                next_transition_at timestamptz,
                version bigint not null,
                created_at timestamptz not null,
                primary key (entry_id, variant),
                constraint variant_heads_variant check (variant = 'shared' or variant ~ '{$locale}'),
                constraint variant_heads_draft_revision check (draft_revision_id >= 1),
                constraint variant_heads_published_revision check (published_revision_id >= 1),
                constraint variant_heads_schema_version check (schema_version >= 1),
                constraint variant_heads_release_state check (release_state in ('unreleased', 'released', 'withdrawn')),
                constraint variant_heads_released check (release_state <> 'released' or published_revision_id is not null),
                constraint variant_heads_workflow_state check (workflow_state ~ '^[a-z][a-z0-9_]{0,62}$'),
                constraint variant_heads_version check (version >= 1)
            ) with (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.01)
            SQL);

        $privileges = new TablePrivileges($connection);

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
            $privileges->limitTo($table, [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table '.implode(', ', array_reverse(self::TABLES)));
    }
};

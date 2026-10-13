<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What placement.set_slug and node.set_route version 2 need from the schema (PRD 5.9), added by
 * the owner role: the redirects for content that moves without changing identity.
 *
 * Slugs. `slug_history` holds, per (node, locale, slug), the placement that had the slug before it
 * was renamed, so a URL that was out there answers 301 to the placement's current URL in one hop.
 * A row points at the placement and never at another history row: three renames of one placement
 * leave three rows that all name the placement, and a slug that is renamed back to one in the
 * history loses the history row, because the live lookup answers it. A slug another placement
 * takes later is answered by the live lookup first, and its history row is replaced when that
 * placement is renamed in turn.
 *
 * Routes. `node_route_history` holds, per (site, locale, route), the node that had the route
 * before node.set_route version 2 changed it: a prefix redirect, so a request below the old route
 * answers 301 below the node's current route, whatever the number of placements below it. The
 * node's current route is read from `node_routes`, so the redirect holds after several changes.
 * `node_routes` gets `node_routes_change`, the UPDATE policy the change writes under.
 *
 * Both tables have forced row level security: every context reads them, the anonymous one
 * included, because the public resolves URLs against them and they hold no content; the app role
 * writes, replaces and removes a row under the call's actor context only for a node the actor's
 * regions reach, as the command that renames the placement or the route is decided on that node
 * (PRD 5.10); without a context the tables hold nothing for anyone.
 *
 * Withdrawn placements. The public answer for a URL whose placement was withdrawn is 410, gone,
 * because withdrawn is sticky (invariant 7, PRD 8.10), and the lookup of a slug reads a withdrawn
 * placement when no other has the slug; but `placement_locales_released` let every context read
 * live rows alone, so the anonymous context answered 404 instead. `placement_locales_withdrawn`
 * lets every context read the withdrawn rows of the released stage too: a placement's slug and
 * ids, which the URL that was out there gave away already, and never a field of the entry.
 */
return new class extends Migration
{
    /** A locale: a BCP 47 language with optional subtags, as in `site_locales`. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;

        $connection->statement(<<<SQL
            create table slug_history (
                node_id uuid not null references nodes (id),
                locale text not null,
                slug text not null,
                placement_id uuid not null references placements (id),
                changeset_id uuid not null,
                created_at timestamptz not null,
                primary key (node_id, locale, slug),
                constraint slug_history_locale check (locale ~ '{$locale}'),
                constraint slug_history_slug check (slug ~ '^[^/[:space:]]+$' and slug not in ('.', '..'))
            )
            SQL);
        $connection->statement('create index slug_history_placement on slug_history (placement_id)');
        $connection->statement(<<<'SQL'
            create table node_route_history (
                site_id uuid not null,
                locale text not null,
                route text not null,
                node_id uuid not null references nodes (id),
                changeset_id uuid not null,
                created_at timestamptz not null,
                primary key (site_id, locale, route),
                constraint node_route_history_site_locale_fkey foreign key (site_id, locale) references site_locales (site_id, locale),
                constraint node_route_history_route check (route ~ '^(/|(/[^/[:space:]]+)+)$')
            )
            SQL);
        $connection->statement('create index node_route_history_node on node_route_history (node_id)');

        foreach (['slug_history', 'node_route_history'] as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
            $connection->statement(sprintf('create policy %1$s_read on %1$s for select using (cms_access_context() is not null)', $table));
            $connection->statement(sprintf('create policy %1$s_write on %1$s for insert with check (cms_access_node(node_id))', $table));
            $connection->statement(sprintf('create policy %1$s_change on %1$s for update using (cms_access_node(node_id)) with check (cms_access_node(node_id))', $table));
            $connection->statement(sprintf('create policy %1$s_remove on %1$s for delete using (cms_access_node(node_id))', $table));
        }

        $connection->statement('create policy node_routes_change on node_routes for update using (cms_access_node(node_id)) with check (cms_access_node(node_id))');
        $connection->statement("create policy placement_locales_withdrawn on placement_locales for select using (stage = 'released' and visibility = 'withdrawn' and cms_access_context() is not null)");
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop policy placement_locales_withdrawn on placement_locales');
        $connection->statement('drop policy node_routes_change on node_routes');
        $connection->statement('drop table node_route_history, slug_history');
    }
};

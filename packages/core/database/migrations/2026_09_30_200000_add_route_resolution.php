<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What path.resolve reads (PRD 5.9, 5.10), added by the owner role.
 *
 * The routes. `node_routes` had no policy and was closed to every role. Every context, the
 * anonymous one included, now reads it (`node_routes_read`): the routes are what the public
 * resolves a URL against, as the sites and their locales are, and they hold no content.
 *
 * The routed nodes. A resolution needs, for the node a route reaches, its kind and a mount's
 * source, and for the canonical placement's node, the root of its tree, which names its site. The
 * anonymous context reads no node, and an actor only those its regions reach or its grants name;
 * the placement and entry commands rely on that, since a node an actor cannot read is refused as
 * unauthorized or absent. So the nodes' policies stay as they are, and
 * `cms_routed_node(node)` returns exactly those three columns of one node that has a route, as the
 * owner role (SECURITY DEFINER with a fixed search_path), under any actor context and nothing
 * without one. A route already names its node to every context, and these columns hold no content.
 *
 * The withdrawn placements. The lookup of a slug below a node (PRD 5.9 step 3) reads the placement
 * that is not withdrawn through `placement_locales_slug_key`, the partial unique index of invariant
 * 15, and otherwise a withdrawn one, so the explanation can say the placement was withdrawn (PRD
 * 6.6 rung 5). `placement_locales_withdrawn_slug` is the partial index of that second lookup.
 *
 * The function is created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('create policy node_routes_read on node_routes for select using (cms_access_context() is not null)');
        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_routed_node(p_node uuid) returns table (kind text, mount_source_id uuid, root_id uuid) '
                    'language sql stable security definer set search_path = %I, pg_temp as $body$ '
                    'select n.kind, n.mount_source_id, ltree2text(subpath(n.path, 0, 1))::uuid from nodes n '
                    'where n.id = p_node and cms_access_context() is not null '
                    'and exists (select 1 from node_routes r where r.node_id = n.id) $body$',
                    current_schema()
                );
            end $do$
            SQL);
        $connection->statement(<<<'SQL'
            create index placement_locales_withdrawn_slug on placement_locales (node_id, locale, slug, stage)
                where visibility = 'withdrawn'
            SQL);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop index placement_locales_withdrawn_slug');
        $connection->statement('drop function cms_routed_node(uuid)');
        $connection->statement('drop policy node_routes_read on node_routes');
    }
};

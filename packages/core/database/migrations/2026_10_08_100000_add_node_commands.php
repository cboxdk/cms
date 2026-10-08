<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What node.create, node.archive and node.set_route need from the schema (PRD 5.8, 5.9, 5.10, 6.4,
 * gap report G30), added by the owner role.
 *
 * The lifecycle of a node. `nodes` gets `lifecycle`, active or archived, active for every node
 * there is and for every node created from now on (PRD 6.4). An archived node is read-only
 * structure: node.create takes no parent that is archived, node.set_route no node that is, and the
 * content below it keeps the visibility it has.
 *
 * Writing the tree. Until now the structure was written only by the testkit's structure fixtures,
 * as the owner role through the blanket policies `nodes_owner_write` and `node_routes_owner_write`,
 * which let the owner write every row of those two tables and so narrowed "forced row level
 * security holds for the owner too" (PRD 4.2). The node commands replace them:
 *
 * - `nodes` already had `nodes_actor`, FOR ALL over `cms_access_reaches(path)`, so the app role
 *   creates and archives a node under the call's actor context, on a path the actor's grants reach,
 *   and on no other (PRD 5.10). `nodes_owner_write` is dropped and `nodes_owner_read`, SELECT for
 *   the owner alone, takes its place, as `entries_owner_read` does: the lookups below read the tree
 *   past the actor's regions, and the owner writes no node.
 * - `node_routes` gets `node_routes_write`, INSERT where the actor's regions reach the node, so
 *   node.set_route writes as the app role; `node_routes_owner_write` is dropped and
 *   `node_routes_owner_read` takes its place for the lookups.
 * - A site's registration writes a root node and its root routes as the owner, before any grant
 *   reaches them (PRD 11.14), so both tables get one narrow policy for exactly that:
 *   `nodes_site_root` takes a root node of kind site at version 1, and `node_routes_site_root` the
 *   route `/`, each only while `cms_structure_registering()` holds, which says that the current
 *   transaction wrote a site.register changeset by the actor of the context, as
 *   `cms_structure_register_site` itself checks.
 *
 * Reading the tree. The tree is one across the sites, and a command must answer a node outside the
 * actor's regions as unauthorized rather than as absent, a route resolves to one node whoever reads
 * it, and archiving a node must not hide a placement the reader cannot see. These lookups therefore
 * run as the owner role (SECURITY DEFINER with a fixed search_path), one row at a time, never a
 * list, and each raises 42501 without an actor context:
 *
 * - `cms_structure_node(node)` returns one row for a node that exists: its parent, kind, path,
 *   lifecycle and version, and whether the actor's regions reach it.
 * - `cms_structure_route_holder(site, locale, route)` returns the node that has the route on the
 *   site in the locale, or null.
 * - `cms_structure_node_route(site, locale, node)` returns the route the node has on the site in
 *   the locale, or null.
 * - `cms_structure_node_placement(node, at)` returns a placement below the node, the node itself
 *   included, whose released stage is visible at the time or later, or null when none is. A mount
 *   below the node is not followed: it shows its source's placements, which stay where they are.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_structure_node(uuid)',
        'cms_structure_route_holder(uuid, text, text)',
        'cms_structure_node_route(uuid, text, uuid)',
        'cms_structure_node_placement(uuid, timestamptz)',
    ];

    /** @var list<string> the tables whose blanket owner write policy the node commands replace */
    private const array REPLACED = ['nodes', 'node_routes'];

    /** The check every lookup starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$%s runs only under an actor context$$;
        end if;
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement("alter table nodes add column lifecycle text not null default 'active'");
        $connection->statement("alter table nodes add constraint nodes_lifecycle check (lifecycle in ('active', 'archived'))");

        foreach (self::REPLACED as $table) {
            $connection->statement(sprintf('drop policy if exists %1$s_owner_write on %1$s', $table));
            $connection->statement(sprintf('create policy %1$s_owner_read on %1$s for select to current_user using (true)', $table));
        }

        $connection->statement(<<<'SQL'
            create or replace function cms_structure_registering() returns boolean language sql stable as $f$
                select exists (
                    select 1 from changesets c
                    where c.actor_id = cms_access_actor()
                      and c.command = 'site.register'
                      and c.xid = pg_current_xact_id()
                )
            $f$
            SQL);

        $connection->statement("create policy nodes_site_root on nodes for insert with check (parent_id is null and kind = 'site' and version = 1 and cms_structure_registering())");
        $connection->statement("create policy node_routes_site_root on node_routes for insert with check (route = '/' and cms_structure_registering())");
        $connection->statement('create policy node_routes_write on node_routes for insert with check (cms_access_node(node_id))');

        $this->definer(
            'cms_structure_node(p_node uuid) returns table (parent_id uuid, kind text, path text, lifecycle text, version bigint, reachable boolean) language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select n.parent_id, n.kind, ltree2text(n.path), n.lifecycle, n.version, cms_access_reaches(n.path)
                        from nodes n
                        where n.id = p_node;
                end
                SQL,
            'cms_structure_node',
        );

        $this->definer(
            'cms_structure_route_holder(p_site uuid, p_locale text, p_route text) returns uuid language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (
                        select r.node_id from node_routes r
                        where r.site_id = p_site and r.locale = p_locale and r.route = p_route
                    );
                end
                SQL,
            'cms_structure_route_holder',
        );

        $this->definer(
            'cms_structure_node_route(p_site uuid, p_locale text, p_node uuid) returns text language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (
                        select r.route from node_routes r
                        where r.site_id = p_site and r.locale = p_locale and r.node_id = p_node
                    );
                end
                SQL,
            'cms_structure_node_route',
        );

        $this->definer(
            'cms_structure_node_placement(p_node uuid, p_at timestamptz) returns uuid language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (
                        select pl.placement_id
                        from placement_locales pl
                        join nodes n on n.id = pl.node_id
                        where pl.stage = $$released$$
                          and pl.visibility not in ($$hidden$$, $$withdrawn$$)
                          and (pl.live_until is null or pl.live_until > p_at)
                          and n.path <@ (select path from nodes where id = p_node)
                        order by pl.placement_id
                        limit 1
                    );
                end
                SQL,
            'cms_structure_node_placement',
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));
        $connection->statement('drop policy node_routes_write on node_routes');
        $connection->statement('drop policy node_routes_site_root on node_routes');
        $connection->statement('drop policy nodes_site_root on nodes');
        $connection->statement('drop function cms_structure_registering()');

        foreach (self::REPLACED as $table) {
            $connection->statement(sprintf('drop policy %1$s_owner_read on %1$s', $table));
        }

        $connection->statement('alter table nodes drop constraint nodes_lifecycle');
        $connection->statement('alter table nodes drop column lifecycle');
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path. The
     * body is a literal of the format() that creates it, so its quotes and percent signs are
     * doubled, and the guard is put in before that.
     */
    private function definer(string $signature, string $body, string $name): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            str_replace(["'", '%'], ["''", '%%'], sprintf($body, sprintf(self::GUARD, $name))),
        ));
    }
};

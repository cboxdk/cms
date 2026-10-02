<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What the listing queries role.list, grant.list, actor.list and node.list read (PRD 5.8, 5.10,
 * 5.16, 12.2), added by the owner role. Every actor reads the roles and their permissions itself,
 * so role.list needs nothing here. The app role reads only its own actor's grants, no actor and
 * only the nodes its regions reach, and a node's path label names the nodes above it, so the other
 * three read through functions that run as the owner role (SECURITY DEFINER, with a fixed
 * search_path, and plan_cache_mode = auto for the access functions as every one has it), each under an actor
 * context and returning nothing without one:
 *
 * `cms_access_holds_permission(command)` says whether the context's actor holds a grant that has
 * not ended and allows a role whose permissions name the command, the backstop of the listings
 * that need a permission; the query pipeline authorizes them first.
 *
 * `cms_access_node_label(path)` gives the path label of a node the context's regions reach, null
 * for any other: one segment per node from the root of its tree down to it, joined by `/`, the
 * handle of the site whose root it is for the root (its kind for a tree that is no site's), and
 * below it the last segment of the node's route, in the first locale and site in text order, or
 * the node's kind when it has none.
 *
 * `cms_access_node_list(after, limit)` gives the nodes the context's regions reach in tree order
 * (by path), after the node `after` when it is not null, with their parent, kind, site, site
 * handle and path label.
 *
 * `cms_access_grant_list(after, limit)` gives the grants that have not ended on nodes the
 * context's regions reach, in the order of their ids, after `after`, with the role's handle, the
 * node's path label and the locales, only for an actor that holds grant.list. The display name and
 * email of a grant's actor are there for the context's own actor, and for any other only when the
 * context's classification access allows personal (PRD 12.2); otherwise they are null.
 *
 * `cms_identity_actor_list(after, limit)` gives the staff actors in the order of their ids, after
 * `after`, with their state and version, only for an actor that holds actor.list. The profile is
 * there for the context's own actor, and for any other actor only where `actor_profiles_listed`
 * would let the app role read it (`cms_access_profile_listed`: personal access and actor.list);
 * otherwise it is null.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->definer(
            'cms_access_holds_permission(p_command text) returns boolean language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        return false;
                    end if;

                    return exists (
                        select 1
                        from grants g
                        join role_permissions p on p.role_id = g.role_id
                        where g.actor_id = cms_access_actor()
                          and g.ended_changeset_id is null
                          and g.effect = $$allow$$
                          and p.command = p_command
                    );
                end
                SQL,
        );

        $this->definer(
            'cms_access_node_label(p_path ltree) returns text language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or p_path is null or not cms_access_reaches(p_path) then
                        return null;
                    end if;

                    return (
                        select string_agg(l.segment, $$/$$ order by l.depth)
                        from (
                            select
                                nlevel(a.path) as depth,
                                case
                                    when nlevel(a.path) = 1 then coalesce((select s.handle from sites s where s.root_node_id = a.id), a.kind)
                                    else coalesce(nullif((
                                        select regexp_replace(r.route, $$^.*/$$, $$$$)
                                        from node_routes r
                                        where r.node_id = a.id
                                        order by r.locale, r.site_id
                                        limit 1
                                    ), $$$$), a.kind)
                                end as segment
                            from nodes a
                            where a.path @> p_path
                        ) l
                    );
                end
                SQL,
        );

        $this->definer(
            'cms_access_node_list(p_after uuid, p_limit integer) returns table (id uuid, parent_id uuid, kind text, site_id uuid, site_handle text, label text) language plpgsql stable',
            <<<'SQL'
                declare
                    v_after ltree;
                begin
                    if cms_access_actor() is null then
                        return;
                    end if;

                    if p_after is not null then
                        select n.path into v_after from nodes n where n.id = p_after;

                        if v_after is null then
                            return;
                        end if;
                    end if;

                    return query
                        select n.id, n.parent_id, n.kind, s.id, s.handle, cms_access_node_label(n.path)
                        from nodes n
                        left join sites s on s.root_node_id = ltree2text(subpath(n.path, 0, 1))::uuid
                        where cms_access_reaches(n.path)
                          and (v_after is null or n.path > v_after)
                        order by n.path
                        limit greatest(p_limit, 0);
                end
                SQL,
        );

        $this->definer(
            'cms_access_grant_list(p_after uuid, p_limit integer) returns table (id uuid, actor_id uuid, role_id uuid, role_handle text, node_id uuid, node_label text, effect text, locales text, version bigint, display_name text, email text) language plpgsql stable',
            <<<'SQL'
                declare
                    v_profiles boolean;
                begin
                    if cms_access_actor() is null or not cms_access_holds_permission($$grant.list$$) then
                        return;
                    end if;

                    v_profiles := cms_access_classification_allows($$personal$$);

                    return query
                        select
                            g.id,
                            g.actor_id,
                            g.role_id,
                            r.handle,
                            g.node_id,
                            cms_access_node_label(n.path),
                            g.effect,
                            g.locales::text,
                            g.version,
                            case when v_profiles or g.actor_id = cms_access_actor() then p.display_name end,
                            case when v_profiles or g.actor_id = cms_access_actor() then p.email end
                        from grants g
                        join nodes n on n.id = g.node_id
                        join roles r on r.id = g.role_id
                        left join actor_profiles p on p.actor_id = g.actor_id
                        where g.ended_changeset_id is null
                          and cms_access_reaches(n.path)
                          and (p_after is null or g.id > p_after)
                        order by g.id
                        limit greatest(p_limit, 0);
                end
                SQL,
        );

        $this->definer(
            'cms_identity_actor_list(p_after uuid, p_limit integer) returns table (id uuid, state text, version bigint, display_name text, email text) language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not cms_access_holds_permission($$actor.list$$) then
                        return;
                    end if;

                    return query
                        select
                            a.id,
                            a.state,
                            a.version,
                            case when a.id = cms_access_actor() or cms_access_profile_listed(a.id) then p.display_name end,
                            case when a.id = cms_access_actor() or cms_access_profile_listed(a.id) then p.email end
                        from actors a
                        left join actor_profiles p on p.actor_id = a.id
                        where a.actor_class = $$staff$$
                          and (p_after is null or a.id > p_after)
                        order by a.id
                        limit greatest(p_limit, 0);
                end
                SQL,
            access: false,
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function cms_identity_actor_list(uuid, integer)');
        $connection->statement('drop function cms_access_grant_list(uuid, integer)');
        $connection->statement('drop function cms_access_node_list(uuid, integer)');
        $connection->statement('drop function cms_access_node_label(ltree)');
        $connection->statement('drop function cms_access_holds_permission(text)');
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path, and
     * for an access function plan_cache_mode = auto, as the access functions have it; the identity
     * functions have only the search_path. The body is a literal of the format() that creates it,
     * so its quotes and percent signs are doubled.
     */
    private function definer(string $signature, string $body, bool $access = true): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp%s as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            $access ? ' set plan_cache_mode = auto' : '',
            str_replace(["'", '%'], ["''", '%%'], $body),
        ));
    }
};

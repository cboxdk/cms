<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * grant.list lists a grant only on a node where the reader may run grant.list (PRD 5.10: a right
 * is decided on the node), not on every node its regions reach through any of its roles. Both
 * functions run as the owner role (SECURITY DEFINER, with a fixed search_path and plan_cache_mode
 * = auto, as every access function has it), under an actor context, and give nothing without one:
 *
 * `cms_access_permits(command, path)` says whether a role of the context's actor whose permissions
 * name the command reaches the node at the path in some locale, by the rule of PermissionRule and
 * the AccessCompiler, over the actor's grants of those roles that have not ended: in a locale, the
 * role's grant nearest above the node, or on it, among the grants that hold in the locale decides,
 * a deny winning over an allow on the same node. The locales are each one a grant names and the
 * locales no grant names, where only the grants that hold in every locale count.
 *
 * `cms_access_grant_list(after, limit)` is replaced: as before, but a grant is listed only when its
 * node is also one cms_access_permits gives for grant.list, so a reader with grant.list on one node
 * and another role on a large branch lists the grants of that one node alone.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    private const string GRANT_LIST = 'cms_access_grant_list(p_after uuid, p_limit integer) returns table (id uuid, actor_id uuid, role_id uuid, role_handle text, node_id uuid, node_label text, effect text, locales text, version bigint, display_name text, email text) language plpgsql stable';

    public function up(): void
    {
        $this->definer(
            'cms_access_permits(p_command text, p_path ltree) returns boolean language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or p_path is null then
                        return false;
                    end if;

                    return exists (
                        with mine as (
                            select g.role_id, n.path, g.effect, g.locales
                            from grants g
                            join nodes n on n.id = g.node_id
                            where g.actor_id = cms_access_actor()
                              and g.ended_changeset_id is null
                              and n.path @> p_path
                              and exists (select 1 from role_permissions p where p.role_id = g.role_id and p.command = p_command)
                        ),
                        locales as (
                            select null::text as locale
                            union
                            select distinct unnest(m.locales) from mine m
                        ),
                        roles_of as (
                            select distinct m.role_id from mine m
                        )
                        select 1
                        from locales l
                        cross join roles_of r
                        where (
                            select m.effect
                            from mine m
                            where m.role_id = r.role_id
                              and (m.locales is null or l.locale = any (m.locales))
                            order by nlevel(m.path) desc, (m.effect = $$deny$$) desc
                            limit 1
                        ) = $$allow$$
                    );
                end
                SQL,
        );

        $this->definer(self::GRANT_LIST, $this->grantList(permitted: true));
    }

    public function down(): void
    {
        $this->definer(self::GRANT_LIST, $this->grantList(permitted: false));

        DB::connection($this->getConnection())->statement('drop function cms_access_permits(text, ltree)');
    }

    /**
     * The body of cms_access_grant_list; with $permitted, a grant is listed only on a node where the
     * reader may run grant.list, without it on every node its regions reach (the definition of
     * `*_add_access_listings.php`).
     */
    private function grantList(bool $permitted): string
    {
        return str_replace('%permitted%', $permitted ? 'and cms_access_permits($$grant.list$$, n.path)' : '', <<<'SQL'
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
                      %permitted%
                      and (p_after is null or g.id > p_after)
                    order by g.id
                    limit greatest(p_limit, 0);
            end
            SQL);
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path and
     * plan_cache_mode = auto, as the access functions have it. The body is a literal of the format()
     * that creates it, so its quotes and percent signs are doubled.
     */
    private function definer(string $signature, string $body): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp set plan_cache_mode = auto as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            str_replace(["'", '%'], ["''", '%%'], $body),
        ));
    }
};

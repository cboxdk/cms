<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The database's backstop for the listings that need a permission, grant.list and actor.list
 * (PRD 5.10, 12.2), decides by the rule of PermissionRule::query() and the AccessCompiler, deny
 * grants included, instead of asking only whether an allow grant exists:
 *
 * `cms_access_holds_permission(command)` is replaced: the context's actor holds the command when a
 * role of it whose permissions name the command reaches, in some locale, the node of one of its
 * allow grants that have not ended, as `cms_access_permits` decides it (the role's grant nearest
 * above the node, or on it, among the grants that hold in the locale, a deny winning over an allow
 * on the same node). A role reaches nothing outside the nodes it holds an allow on, so these are
 * the only nodes to ask about.
 *
 * `cms_access_profile_listed(actor)`, behind the policy `actor_profiles_listed` and the profiles of
 * `cms_identity_actor_list`, is replaced to ask `cms_access_holds_permission('actor.list')` instead
 * of its own copy of the old test.
 *
 * Under the partial unique key on (actor, role, node) the old test gave the same answer, because a
 * role's allow grant is the nearest grant of that role on its own node; the backstop now applies the
 * rule itself and no longer depends on that key. Both functions run as the owner role (SECURITY
 * DEFINER, with a fixed search_path and plan_cache_mode = auto, as every access function has it),
 * under an actor context, and give false without one. They are created with CREATE OR REPLACE,
 * because migrate:fresh drops tables and leaves functions that do not depend on one.
 */
return new class extends Migration
{
    private const string HOLDS = 'cms_access_holds_permission(p_command text) returns boolean language plpgsql stable';

    private const string PROFILE_LISTED = 'cms_access_profile_listed(p_actor uuid) returns boolean language plpgsql stable';

    public function up(): void
    {
        $this->definer(self::HOLDS, <<<'SQL'
            begin
                if cms_access_actor() is null then
                    return false;
                end if;

                return exists (
                    select 1
                    from grants g
                    join nodes n on n.id = g.node_id
                    where g.actor_id = cms_access_actor()
                      and g.ended_changeset_id is null
                      and g.effect = $$allow$$
                      and exists (select 1 from role_permissions p where p.role_id = g.role_id and p.command = p_command)
                      and cms_access_permits(p_command, n.path)
                );
            end
            SQL);

        $this->definer(self::PROFILE_LISTED, <<<'SQL'
            begin
                if cms_access_actor() is null or not cms_access_classification_allows($$personal$$) then
                    return false;
                end if;

                return exists (select 1 from actors a where a.id = p_actor and a.actor_class = $$staff$$)
                    and cms_access_holds_permission($$actor.list$$);
            end
            SQL);
    }

    public function down(): void
    {
        $this->definer(self::HOLDS, <<<'SQL'
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
            SQL);

        $this->definer(self::PROFILE_LISTED, <<<'SQL'
            begin
                if cms_access_actor() is null or not cms_access_classification_allows($$personal$$) then
                    return false;
                end if;

                return exists (select 1 from actors a where a.id = p_actor and a.actor_class = $$staff$$)
                    and exists (
                        select 1
                        from grants g
                        join role_permissions p on p.role_id = g.role_id
                        where g.actor_id = cms_access_actor()
                          and g.ended_changeset_id is null
                          and g.effect = $$allow$$
                          and p.command = $$actor.list$$
                    );
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

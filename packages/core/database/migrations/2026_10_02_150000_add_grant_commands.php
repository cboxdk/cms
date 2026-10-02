<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What grant.assign and grant.revoke need from the schema (PRD 5.10, 6.4, invariant 31), added by
 * the owner role. The app role reads only its own actor's grants and writes none, so every read of
 * another actor's grant, every lock and every write is a function that runs as the owner role
 * (SECURITY DEFINER, with a fixed search_path, and plan_cache_mode = auto as every access function
 * has it).
 *
 * `cms_access_grant(grant)` gives one grant, ended or not, with whether it has ended, but only
 * under an actor context whose regions reach the grant's node (`cms_access_reaches`), so an actor
 * learns nothing of the grants outside its part of the tree. `cms_access_grant_held(actor, role,
 * node)` says whether the actor holds the role on the node with a grant that has not ended, which
 * the partial unique index `grants_actor_role_node_key` allows once.
 *
 * `cms_access_lock_grant(grant, for_update)` and `cms_access_lock_role(role, for_update)` lock one
 * row for the rest of the caller's transaction and return its version, or null when no row has the
 * id: FOR SHARE when the changeset only read it, FOR NO KEY UPDATE when it changes it, as
 * `cms_identity_lock_actor` does for an actor. `cms_access_lock_grant_slot(actor, role, node)`
 * locks the grant of the actor, role and node that has not ended, FOR SHARE, and says whether
 * there is one; the commit takes the slot's advisory lock first, so two assigns of one slot commit
 * one after the other. All three run only under an actor context.
 *
 * `cms_access_assign_grant(...)` inserts a grant at version 1 and `cms_access_revoke_grant(grant,
 * version, changeset)` ends one at the version given with the changeset, as a deactivation ends an
 * actor's grants, and returns its actor, role and node. Each runs only in the transaction of a
 * changeset of its own command by the actor of the context (the changeset's xid is the
 * transaction's); anything else raises 42501 and writes nothing. An assign raises for an actor that
 * is not an active staff or service actor, and a revoke for a grant that is not at the version
 * before the one given or has ended; the action refuses both first, so that is the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->definer(
            'cms_access_grant(p_grant uuid) returns table (actor_id uuid, role_id uuid, node_id uuid, effect text, locales text[], version bigint, ended boolean) language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        return;
                    end if;

                    return query
                        select g.actor_id, g.role_id, g.node_id, g.effect, g.locales, g.version, g.ended_changeset_id is not null
                        from grants g
                        join nodes n on n.id = g.node_id
                        where g.id = p_grant and cms_access_reaches(n.path);
                end
                SQL,
        );

        $this->definer(
            'cms_access_grant_held(p_actor uuid, p_role uuid, p_node uuid) returns boolean language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        return false;
                    end if;

                    return exists (
                        select 1 from grants g
                        where g.actor_id = p_actor and g.role_id = p_role and g.node_id = p_node and g.ended_changeset_id is null
                    );
                end
                SQL,
        );

        foreach (['grant' => 'grants', 'role' => 'roles'] as $kind => $table) {
            $this->definer(
                sprintf('cms_access_lock_%s(p_id uuid, p_for_update boolean) returns bigint language plpgsql volatile', $kind),
                sprintf(<<<'SQL'
                    declare
                        v_version bigint;
                    begin
                        if cms_access_actor() is null then
                            raise exception using errcode = $$42501$$, message = $$cms_access_lock_%1$s runs only under an actor context$$;
                        end if;

                        if p_for_update then
                            select t.version into v_version from %2$s t where t.id = p_id for no key update;
                        else
                            select t.version into v_version from %2$s t where t.id = p_id for share;
                        end if;

                        return v_version;
                    end
                    SQL, $kind, $table),
            );
        }

        $this->definer(
            'cms_access_lock_grant_slot(p_actor uuid, p_role uuid, p_node uuid) returns boolean language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_lock_grant_slot runs only under an actor context$$;
                    end if;

                    perform 1 from grants g
                    where g.actor_id = p_actor and g.role_id = p_role and g.node_id = p_node and g.ended_changeset_id is null
                    for share;

                    return found;
                end
                SQL,
        );

        $this->definer(
            'cms_access_assign_grant(p_grant uuid, p_actor uuid, p_role uuid, p_node uuid, p_effect text, p_locales text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$grant.assign$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_assign_grant runs only in the transaction of a grant.assign changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$a grant is created at version 1, not %s$$, p_version);
                    end if;

                    if not exists (
                        select 1 from actors a where a.id = p_actor and a.actor_class in ($$staff$$, $$service$$) and a.state = $$active$$
                    ) then
                        raise exception using errcode = $$22023$$, message = format($$only an active staff or service actor gets a grant, and %s is not one$$, p_actor);
                    end if;

                    insert into grants (id, actor_id, role_id, node_id, effect, locales, version, created_at)
                    values (p_grant, p_actor, p_role, p_node, p_effect, p_locales, p_version, p_at);
                end
                SQL,
        );

        $this->definer(
            'cms_access_revoke_grant(p_grant uuid, p_version bigint, p_changeset uuid) returns table (actor_id uuid, role_id uuid, node_id uuid) language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$grant.revoke$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_revoke_grant runs only in the transaction of a grant.revoke changeset by the actor of the context$$;
                    end if;

                    return query
                        update grants g
                           set version = p_version,
                               ended_changeset_id = p_changeset
                         where g.id = p_grant
                           and g.version = p_version - 1
                           and g.ended_changeset_id is null
                        returning g.actor_id, g.role_id, g.node_id;

                    if not found then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$no grant %s that has not ended is at version %s$$, p_grant, p_version - 1);
                    end if;
                end
                SQL,
        );
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function '.implode(', ', [
            'cms_access_revoke_grant(uuid, bigint, uuid)',
            'cms_access_assign_grant(uuid, uuid, uuid, uuid, text, text[], bigint, timestamptz, uuid)',
            'cms_access_lock_grant_slot(uuid, uuid, uuid)',
            'cms_access_lock_role(uuid, boolean)',
            'cms_access_lock_grant(uuid, boolean)',
            'cms_access_grant_held(uuid, uuid, uuid)',
            'cms_access_grant(uuid)',
        ]));
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path and
     * plan_cache_mode = auto, as the access functions have it. The body is a literal of the
     * format() that creates it, so its quotes and percent signs are doubled.
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

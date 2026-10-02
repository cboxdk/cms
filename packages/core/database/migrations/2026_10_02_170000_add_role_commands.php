<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What role.create and role.set_permissions need from the schema (PRD 5.10, 6.4, invariant 31),
 * added by the owner role. The app role reads the roles and their permissions but writes none, and
 * reads only its own actor's grants, so every lock, every write and every read of another actor's
 * grant is a function that runs as the owner role (SECURITY DEFINER, with a fixed search_path, and
 * plan_cache_mode = auto as every access function has it).
 *
 * `cms_access_role_grants(role)` gives every grant of the role that has not ended, whoever holds it
 * and wherever it is, because a change of the role reaches every holder and the escalation guard
 * must see each node where it is granted. `cms_access_role_grants_version(role)` gives one more
 * than the number of grants ever given of the role, ended ones included: the version of the role's
 * set of grants, which moves with every grant.assign of the role. Both run only under an actor
 * context.
 *
 * `cms_access_lock_role_handle(handle)` locks the role with the handle FOR SHARE and says whether
 * there is one; the commit takes the handle's advisory lock first, so two creates of one handle
 * commit one after the other. `cms_access_lock_grants(grants, for_update)` locks the rows of several
 * grants in id order and gives the version of each that exists, as `cms_access_lock_grant` does for
 * one. Both run only under an actor context.
 *
 * `cms_access_create_role(...)` inserts a role at version 1 with a row of role_permissions per
 * permission; `cms_access_set_role_permissions(role, permissions, version, at, changeset)` moves a
 * role from the version before the one given to it, removes the permissions the list leaves out and
 * adds those it lacked; `cms_access_role_grants_changed(grants, versions, changeset)` moves each
 * grant from the version before the one given to it and returns its actor, role and node. Each
 * runs only in the transaction of a changeset of its own command by the actor of the context (the
 * changeset's xid is the transaction's); anything else raises 42501 and writes nothing. A role or
 * grant that is not at the version before the one given raises P0002; the commit checks the
 * versions first under its locks, so that is the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->definer(
            'cms_access_role_grants(p_role uuid) returns table (id uuid, actor_id uuid, node_id uuid, effect text, locales text[], version bigint) language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        return;
                    end if;

                    return query
                        select g.id, g.actor_id, g.node_id, g.effect, g.locales, g.version
                        from grants g
                        where g.role_id = p_role and g.ended_changeset_id is null
                        order by g.id;
                end
                SQL,
        );

        $this->definer(
            'cms_access_role_grants_version(p_role uuid) returns bigint language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_role_grants_version runs only under an actor context$$;
                    end if;

                    return (select count(*) + 1 from grants g where g.role_id = p_role);
                end
                SQL,
        );

        $this->definer(
            'cms_access_lock_role_handle(p_handle text) returns boolean language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_lock_role_handle runs only under an actor context$$;
                    end if;

                    perform 1 from roles r where r.handle = p_handle for share;

                    return found;
                end
                SQL,
        );

        $this->definer(
            'cms_access_lock_grants(p_ids uuid[], p_for_update boolean) returns table (id uuid, version bigint) language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_lock_grants runs only under an actor context$$;
                    end if;

                    if p_for_update then
                        return query select g.id, g.version from grants g where g.id = any(p_ids) order by g.id for no key update;
                    else
                        return query select g.id, g.version from grants g where g.id = any(p_ids) order by g.id for share;
                    end if;
                end
                SQL,
        );

        $this->definer(
            'cms_access_create_role(p_role uuid, p_handle text, p_ceiling text, p_permissions text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$role.create$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_create_role runs only in the transaction of a role.create changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$a role is created at version 1, not %s$$, p_version);
                    end if;

                    insert into roles (id, handle, classification_ceiling, version, created_at)
                    values (p_role, p_handle, p_ceiling, p_version, p_at);

                    insert into role_permissions (role_id, command, created_at)
                    select p_role, p.command, p_at from unnest(p_permissions) as p(command);
                end
                SQL,
        );

        $this->definer(
            'cms_access_set_role_permissions(p_role uuid, p_permissions text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$role.set_permissions$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_set_role_permissions runs only in the transaction of a role.set_permissions changeset by the actor of the context$$;
                    end if;

                    update roles r set version = p_version where r.id = p_role and r.version = p_version - 1;

                    if not found then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$no role %s is at version %s$$, p_role, p_version - 1);
                    end if;

                    delete from role_permissions p where p.role_id = p_role and not (p.command = any(p_permissions));

                    insert into role_permissions (role_id, command, created_at)
                    select p_role, n.command, p_at from unnest(p_permissions) as n(command)
                    on conflict (role_id, command) do nothing;
                end
                SQL,
        );

        $this->definer(
            'cms_access_role_grants_changed(p_grants uuid[], p_versions bigint[], p_changeset uuid) returns table (id uuid, actor_id uuid, role_id uuid, node_id uuid) language plpgsql volatile',
            <<<'SQL'
                declare
                    v_moved integer;
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$role.set_permissions$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_role_grants_changed runs only in the transaction of a role.set_permissions changeset by the actor of the context$$;
                    end if;

                    return query
                        update grants g
                           set version = n.version
                          from unnest(p_grants, p_versions) as n(id, version)
                         where g.id = n.id
                           and g.version = n.version - 1
                        returning g.id, g.actor_id, g.role_id, g.node_id;

                    get diagnostics v_moved = row_count;

                    if v_moved <> coalesce(array_length(p_grants, 1), 0) then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$%s of %s grants were at the version before the one given$$, v_moved, coalesce(array_length(p_grants, 1), 0));
                    end if;
                end
                SQL,
        );
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function '.implode(', ', [
            'cms_access_role_grants_changed(uuid[], bigint[], uuid)',
            'cms_access_set_role_permissions(uuid, text[], bigint, timestamptz, uuid)',
            'cms_access_create_role(uuid, text, text, text[], bigint, timestamptz, uuid)',
            'cms_access_lock_grants(uuid[], boolean)',
            'cms_access_lock_role_handle(text)',
            'cms_access_role_grants_version(uuid)',
            'cms_access_role_grants(uuid)',
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

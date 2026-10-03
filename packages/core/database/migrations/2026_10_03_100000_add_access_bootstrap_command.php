<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The owner functions the one-time access bootstrap writes through (PRD 5.10, GUARDRAILS 2.1),
 * added by the owner role. access.bootstrap is one command whose plan creates the bootstrap role
 * and grants it, so the role and its grant commit in one changeset, and its writes are role.create's
 * and grant.assign's.
 *
 * `cms_access_create_role(...)` and `cms_access_assign_grant(...)` therefore run in the transaction
 * of a changeset of their own command or of access.bootstrap, by the actor of the context (the
 * changeset's xid is the transaction's); anything else still raises 42501 and writes nothing. Their
 * bodies are otherwise those of the migrations that added them. down() gives them back their own
 * command alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->functions(['role.create', 'access.bootstrap'], ['grant.assign', 'access.bootstrap']);
    }

    public function down(): void
    {
        $this->functions(['role.create'], ['grant.assign']);
    }

    /**
     * Both functions, each allowed in a changeset of the commands given.
     *
     * @param  list<string>  $create  the commands whose changesets may create a role
     * @param  list<string>  $assign  the commands whose changesets may assign a grant
     */
    private function functions(array $create, array $assign): void
    {
        $this->definer(
            'cms_access_create_role(p_role uuid, p_handle text, p_ceiling text, p_permissions text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            sprintf(<<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command in (%s)
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_create_role runs only in the transaction of a %s changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$a role is created at version 1, not %%s$$, p_version);
                    end if;

                    insert into roles (id, handle, classification_ceiling, version, created_at)
                    values (p_role, p_handle, p_ceiling, p_version, p_at);

                    insert into role_permissions (role_id, command, created_at)
                    select p_role, p.command, p_at from unnest(p_permissions) as p(command);
                end
                SQL, $this->literals($create), implode(' or ', $create)),
        );

        $this->definer(
            'cms_access_assign_grant(p_grant uuid, p_actor uuid, p_role uuid, p_node uuid, p_effect text, p_locales text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            sprintf(<<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command in (%s)
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_access_assign_grant runs only in the transaction of a %s changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$a grant is created at version 1, not %%s$$, p_version);
                    end if;

                    if not exists (
                        select 1 from actors a where a.id = p_actor and a.actor_class in ($$staff$$, $$service$$) and a.state = $$active$$
                    ) then
                        raise exception using errcode = $$22023$$, message = format($$only an active staff or service actor gets a grant, and %%s is not one$$, p_actor);
                    end if;

                    insert into grants (id, actor_id, role_id, node_id, effect, locales, version, created_at)
                    values (p_grant, p_actor, p_role, p_node, p_effect, p_locales, p_version, p_at);
                end
                SQL, $this->literals($assign), implode(' or ', $assign)),
        );
    }

    /**
     * The command names as dollar-quoted literals, joined for an IN list.
     *
     * @param  list<string>  $commands
     */
    private function literals(array $commands): string
    {
        return implode(', ', array_map(static fn (string $command): string => '$$'.$command.'$$', $commands));
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path and
     * plan_cache_mode = auto, as the access functions have it. The body is a literal of the
     * format() call, so its quotes and percent signs are doubled.
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

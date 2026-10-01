<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The read the access resolver and the authorizers need for an actor that acts on behalf of others
 * (PRD 5.16, 2.31, 22): its effective grants are the intersection of its own grants and the current
 * compiled grants of every actor of its on-behalf-of chain, so the kernel reads the grants of those
 * actors too. The app role reads only the grants of the context's actor (`grants_actor`), so the
 * read runs as the owner, past that policy, and only for an actor the context's actor acts for:
 *
 * - `cms_delegator_grants(actor, command)` returns the grants of the actor that have not ended, with
 *   the role's id and ceiling, the node's path as text, the effect and the locales, ordered by the
 *   grant's id; with a command, only the grants of the roles whose permissions name it. It raises
 *   42501 without an actor context, and returns no row when no credential of the context's actor
 *   is issued on behalf of the actor (`service_credential_delegations`), so a session reads the
 *   grants of none but the people its own credentials act for, and a chain no credential carries
 *   reaches nothing (fail closed).
 *
 * It is PL/pgSQL and sets plan_cache_mode = auto for itself, as the access functions do, so its
 * lookups keep their cached plans under the context's force_custom_plan. It is created with CREATE
 * OR REPLACE, because migrate:fresh drops tables and leaves functions that do not depend on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $body = <<<'SQL'
            begin
                if cms_access_actor() is null then
                    raise exception using errcode = $$42501$$, message = $$cms_delegator_grants runs only under an actor context$$;
                end if;

                if not exists (
                    select 1
                    from service_credentials c
                    join service_credential_delegations d on d.credential_id = c.id
                    where c.actor_id = cms_access_actor() and d.actor_id = p_actor
                ) then
                    return;
                end if;

                return query
                    select g.role_id, r.classification_ceiling, n.path::text, g.effect, g.locales
                    from grants g
                    join roles r on r.id = g.role_id
                    join nodes n on n.id = g.node_id
                    where g.actor_id = p_actor
                        and g.ended_changeset_id is null
                        and (p_command is null or exists (
                            select 1 from role_permissions p where p.role_id = g.role_id and p.command = p_command
                        ))
                    order by g.id;
            end
            SQL;

        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp set plan_cache_mode = auto as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            'cms_delegator_grants(p_actor uuid, p_command text) returns table (role_id uuid, classification_ceiling text, path text, effect text, locales text[]) language plpgsql stable',
            str_replace("'", "''", $body),
        ));
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function cms_delegator_grants(uuid, text)');
    }
};

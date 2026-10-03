<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The version of an actor's set of grants (PRD 5.10, 6.2, invariant 31), added by the owner role.
 * The escalation guard decides a grant.assign, grant.revoke of a deny and role.set_permissions from
 * the grants the issuing actor, and each actor it acts on behalf of, holds, so the commit must find
 * them as they were when the guard decided. An actor's set of grants is an aggregate whose version
 * is derived from its rows: one, plus the versions of every grant of the actor, ended ones
 * included, plus the number of those that have ended. A grant is never deleted, a new grant adds
 * its version, a revocation or a change of its role's permissions moves its version, and a
 * deactivation ends it, so every change of what the actor holds moves the version up.
 *
 * `cms_access_actor_grants_versions(actors)` gives that version of each actor named, also of one
 * the app role may not read the grants of, and `cms_access_lock_actor_grants(actors)` first takes a
 * transaction-scoped advisory lock on each actor's set, in actor id order, the order of the
 * aggregate keys the commit locks in, and then gives the versions. The lock is exclusive whether
 * the changeset only read the set or changes the actor's grants, because the commit cannot tell
 * the one from the other: no mutation names the set. Every command that changes an actor's grants
 * reads its set, so a change that has not committed holds the lock and the guard's command waits
 * for it and then reads the version it left, and a change that comes later waits for the guard's
 * command. The function is VOLATILE, so the query after the locks reads with a snapshot of its own
 * and sees what committed while it waited. Both run only under an actor context and raise 42501
 * without one; both run as the owner role (SECURITY DEFINER, with a fixed search_path, and
 * plan_cache_mode = auto as every access function has it).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->definer(
            'cms_access_actor_grants_versions(p_actors uuid[]) returns table (actor_id uuid, version bigint) language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_actor_grants_versions runs only under an actor context$$;
                    end if;

                    return query
                        select a.id, (1 + coalesce(sum(g.version), 0) + count(g.ended_changeset_id))::bigint
                        from (select distinct u.id from unnest(p_actors) as u(id)) as a
                        left join grants g on g.actor_id = a.id
                        group by a.id
                        order by a.id;
                end
                SQL,
        );

        $this->definer(
            'cms_access_lock_actor_grants(p_actors uuid[]) returns table (actor_id uuid, version bigint) language plpgsql volatile',
            <<<'SQL'
                declare
                    v_actor uuid;
                begin
                    if cms_access_actor() is null then
                        raise exception using errcode = $$42501$$, message = $$cms_access_lock_actor_grants runs only under an actor context$$;
                    end if;

                    for v_actor in select distinct u.id from unnest(p_actors) as u(id) order by u.id loop
                        perform pg_advisory_xact_lock(hashtextextended($$cbox_cms.actor_grants:$$ || v_actor::text, 0));
                    end loop;

                    return query select v.actor_id, v.version from cms_access_actor_grants_versions(p_actors) as v;
                end
                SQL,
        );
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function '.implode(', ', [
            'cms_access_lock_actor_grants(uuid[])',
            'cms_access_actor_grants_versions(uuid[])',
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

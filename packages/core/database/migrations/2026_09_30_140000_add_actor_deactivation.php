<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What actor.deactivate needs from the schema (PRD 5.16, 6.4, invariant 37), added by the owner role.
 *
 * `actors.deactivation_source` notes what deactivated the actor, a DeactivationSource: `local` for a
 * local command, `inactivity` for the automatic rule. Only a deactivated actor has one. The sources
 * of the IdP's connections and SSF come with the connections (B6).
 *
 * `grants.ended_changeset_id` ends a direct grant: the changeset that ended it, such as the actor's
 * deactivation. An ended grant gives nothing; it stays in the table, because a reactivated actor
 * does not get its earlier direct grants back and the access report shows them, so they can be
 * granted again (PRD 5.16). One actor, role and node therefore have at most one grant that has not
 * ended: the unique key becomes a partial unique index, and actor_id, whose foreign key the key's
 * index covered, gets its own. The kernel's read of an actor's grants
 * (Access\Adapter\PostgresAccessResolver) and `cms_access_granted`, with which an actor reads the
 * nodes its grants name, skip ended grants.
 *
 * `cms_identity_deactivate_actor(actor, version, source, changeset)` writes the deactivation, as the
 * owner role (SECURITY DEFINER, with a fixed search_path), because the app role writes no identity
 * row and ends no grant itself: the actor becomes deactivated at the version given, its credential
 * generation rises by one, so every credential it holds is refused at once, its source is noted,
 * and its grants that have not ended end with the changeset. It is two statements whatever the
 * number of grants, and it returns the new credential generation and the number of grants it
 * ended. It runs only where the command runs: under an actor context, in a transaction that wrote
 * the changeset as an actor.deactivate by the context's actor (the changeset's xid is the
 * transaction's), and the actor must be active or pending at the version before the one given,
 * which the commit has checked under its lock. Anything else raises and writes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            alter table actors
                add column deactivation_source text,
                add constraint actors_deactivation_source check (deactivation_source in ('local', 'inactivity')),
                add constraint actors_deactivation_source_state check (deactivation_source is null or state = 'deactivated')
            SQL);

        $connection->statement('alter table grants add column ended_changeset_id uuid references changeset_register (changeset_id)');
        $connection->statement('create index grants_ended_changeset_id on grants (ended_changeset_id)');
        $connection->statement('create index grants_actor_id on grants (actor_id)');
        $connection->statement('alter table grants drop constraint grants_actor_role_node_key');
        $connection->statement('create unique index grants_actor_role_node_key on grants (actor_id, role_id, node_id) where ended_changeset_id is null');

        $this->granted('select 1 from grants g where g.node_id = p_node and g.actor_id = cms_access_actor() and g.ended_changeset_id is null');

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_identity_deactivate_actor(p_actor uuid, p_version bigint, p_source text, p_changeset uuid)
                    returns table (credential_generation bigint, grants_ended bigint)
                    language plpgsql volatile security definer set search_path = %I, pg_temp as $body$
                    declare
                        v_generation bigint;
                        v_ended bigint;
                    begin
                        if cms_access_actor() is null or not exists (
                            select 1 from changesets c
                            where c.changeset_id = p_changeset
                              and c.actor_id = cms_access_actor()
                              and c.command = $$actor.deactivate$$
                              and c.xid = pg_current_xact_id()
                        ) then
                            raise exception using
                                errcode = $$42501$$,
                                message = $$cms_identity_deactivate_actor runs only in the transaction of an actor.deactivate changeset by the actor of the context$$;
                        end if;

                        update actors a
                           set state = $$deactivated$$,
                               version = p_version,
                               credential_generation = a.credential_generation + 1,
                               deactivation_source = p_source
                         where a.id = p_actor
                           and a.version = p_version - 1
                           and a.state in ($$active$$, $$pending$$)
                        returning a.credential_generation into v_generation;

                        if v_generation is null then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$no active or pending actor %%s is at version %%s$$, p_actor, p_version - 1);
                        end if;

                        update grants g set ended_changeset_id = p_changeset where g.actor_id = p_actor and g.ended_changeset_id is null;
                        get diagnostics v_ended = row_count;

                        return query select v_generation, v_ended;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function cms_identity_deactivate_actor(uuid, bigint, text, uuid)');
        $this->granted('select 1 from grants g where g.node_id = p_node and g.actor_id = cms_access_actor()');
        $connection->statement('drop index grants_actor_role_node_key');
        $connection->statement('alter table grants add constraint grants_actor_role_node_key unique (actor_id, role_id, node_id)');
        $connection->statement('drop index grants_actor_id');
        $connection->statement('alter table grants drop column ended_changeset_id');
        $connection->statement('alter table actors drop column deactivation_source');
    }

    /**
     * `cms_access_granted`, as the access migration creates it, with the query given.
     */
    private function granted(string $query): void
    {
        DB::connection($this->getConnection())->statement(sprintf(
            'create or replace function cms_access_granted(p_node uuid) returns boolean language plpgsql stable as $f$ begin return exists (%s); end $f$',
            $query,
        ));
    }
};

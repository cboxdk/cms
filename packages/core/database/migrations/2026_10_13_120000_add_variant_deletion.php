<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The owner function variant.delete withdraws a placement with (PRD 5.10, 8.10, 10.3), as the
 * owner role.
 *
 * `cms_placement_withdraw(placement, locale, version, changeset)` withdraws the released stage of
 * the placement in the locale when it is not withdrawn already, whatever its state: it clears its
 * window, its next transition and its canonical flag, because a withdrawn placement is never
 * canonical (invariant 14, `placement_locales_canonical`), sets the placement's version and
 * returns the entry, the node and the state before. It runs only in the transaction of a
 * variant.delete changeset by the context's actor, and raises 42501 otherwise; the placement is at
 * the version before the one given, or at it when the same changeset changed it already. Deleting
 * a translation is decided on the entry's home in the locale, so the placement can be below a node
 * the actor's regions do not reach, where the app role neither reads nor writes it; the function
 * is SECURITY DEFINER with a fixed search_path, which `placements_owner_write` and
 * `placement_locales_owner_write` let through the forced row level security, as
 * `cms_placement_close` does for entry.unpublish.
 *
 * The function is created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    private const string FUNCTION = 'cms_placement_withdraw(uuid, text, bigint, uuid)';

    public function up(): void
    {
        DB::connection($this->getConnection())->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_placement_withdraw(p_placement uuid, p_locale text, p_version bigint, p_changeset uuid)
                    returns table (entry_id uuid, node_id uuid, previous text)
                    language plpgsql volatile security definer set search_path = %I, pg_temp as $body$
                    declare
                        v_entry uuid;
                        v_node uuid;
                        v_previous text;
                    begin
                        if cms_access_actor() is null or not exists (
                            select 1 from changesets c
                            where c.changeset_id = p_changeset
                              and c.actor_id = cms_access_actor()
                              and c.command = $$variant.delete$$
                              and c.xid = pg_current_xact_id()
                        ) then
                            raise exception using
                                errcode = $$42501$$,
                                message = $$cms_placement_withdraw runs only in the transaction of a variant.delete changeset by the actor of the context$$;
                        end if;

                        update placements p
                           set version = p_version
                         where p.id = p_placement
                           and p.version in (p_version - 1, p_version);

                        if not found then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$no placement %%s is at version %%s or %%s$$, p_placement, p_version - 1, p_version);
                        end if;

                        with old as (
                            select o.placement_id, o.stage, o.locale, o.visibility
                            from placement_locales o
                            where o.placement_id = p_placement
                              and o.stage = $$released$$
                              and o.locale = p_locale
                              and o.visibility <> $$withdrawn$$
                            for no key update
                        )
                        update placement_locales pl
                           set visibility = $$withdrawn$$, live_from = null, live_until = null, next_transition_at = null, canonical = false
                          from old
                         where pl.placement_id = old.placement_id and pl.stage = old.stage and pl.locale = old.locale
                        returning pl.entry_id, pl.node_id, old.visibility into v_entry, v_node, v_previous;

                        if v_entry is null then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$the placement %%s has no locale %%s that is not withdrawn$$, p_placement, p_locale);
                        end if;

                        return query select v_entry, v_node, v_previous;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function '.self::FUNCTION);
    }
};

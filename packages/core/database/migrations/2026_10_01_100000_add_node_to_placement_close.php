<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The owner function entry.unpublish closes a placement with (PRD 5.6, 5.10, 6.4), as the owner
 * role, now also returning the node the placement sits under, so placement.visibility_changed
 * names it and the invalidation reaches the answers of the placement's path (PRD 9.4).
 *
 * `cms_placement_close(placement, locale, version, changeset)` hides the released stage of the
 * placement in the locale when it is scheduled or live, clears its window and its next transition,
 * sets the placement's version and returns the entry, the node and the state before. It runs only
 * in the transaction of an entry.unpublish changeset by the context's actor, and raises 42501
 * otherwise; the placement is at the version before the one given, or at it when the same changeset
 * changed it already. Unpublishing is decided on the entry's home, so the placement can be below a
 * node the actor's regions do not reach, where the app role neither reads nor writes it; the
 * function is SECURITY DEFINER with a fixed search_path, which `placements_owner_write` and
 * `placement_locales_owner_write` let through the forced row level security.
 *
 * The function is dropped first, because a function's result cannot be changed by CREATE OR
 * REPLACE, and migrate:fresh drops tables and leaves functions that do not depend on one.
 */
return new class extends Migration
{
    private const string FUNCTION = 'cms_placement_close(uuid, text, bigint, uuid)';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function if exists '.self::FUNCTION);

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_placement_close(p_placement uuid, p_locale text, p_version bigint, p_changeset uuid)
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
                              and c.command = $$entry.unpublish$$
                              and c.xid = pg_current_xact_id()
                        ) then
                            raise exception using
                                errcode = $$42501$$,
                                message = $$cms_placement_close runs only in the transaction of an entry.unpublish changeset by the actor of the context$$;
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
                              and o.visibility in ($$scheduled$$, $$live$$)
                            for no key update
                        )
                        update placement_locales pl
                           set visibility = $$hidden$$, live_from = null, live_until = null, next_transition_at = null
                          from old
                         where pl.placement_id = old.placement_id and pl.stage = old.stage and pl.locale = old.locale
                        returning pl.entry_id, pl.node_id, old.visibility into v_entry, v_node, v_previous;

                        if v_entry is null then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$the placement %%s is not scheduled or live in %%s$$, p_placement, p_locale);
                        end if;

                        return query select v_entry, v_node, v_previous;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);
    }

    /**
     * Back to the function of 2026_09_30_180000_add_publish_commands.php, without the node.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.self::FUNCTION);

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_placement_close(p_placement uuid, p_locale text, p_version bigint, p_changeset uuid)
                    returns table (entry_id uuid, previous text)
                    language plpgsql volatile security definer set search_path = %I, pg_temp as $body$
                    declare
                        v_entry uuid;
                        v_previous text;
                    begin
                        if cms_access_actor() is null or not exists (
                            select 1 from changesets c
                            where c.changeset_id = p_changeset
                              and c.actor_id = cms_access_actor()
                              and c.command = $$entry.unpublish$$
                              and c.xid = pg_current_xact_id()
                        ) then
                            raise exception using
                                errcode = $$42501$$,
                                message = $$cms_placement_close runs only in the transaction of an entry.unpublish changeset by the actor of the context$$;
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
                              and o.visibility in ($$scheduled$$, $$live$$)
                            for no key update
                        )
                        update placement_locales pl
                           set visibility = $$hidden$$, live_from = null, live_until = null, next_transition_at = null
                          from old
                         where pl.placement_id = old.placement_id and pl.stage = old.stage and pl.locale = old.locale
                        returning pl.entry_id, old.visibility into v_entry, v_previous;

                        if v_entry is null then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$the placement %%s is not scheduled or live in %%s$$, p_placement, p_locale);
                        end if;

                        return query select v_entry, v_previous;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);
    }
};

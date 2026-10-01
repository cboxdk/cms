<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What entry.publish and entry.unpublish need from the schema (PRD 5.6, 5.10, 6.4), added by the
 * owner role.
 *
 * Unpublishing. entry.unpublish takes the content of an entry back to unreleased, and the release
 * log records that as the action `unreleased`, without a revision, beside `released` and
 * `withdrawn` (`release_log_action`).
 *
 * The placements of an entry on every site. Publishing and unpublishing are decided on the entry's
 * home (PRD 5.10): unpublishing closes every placement of the entry, on every site, also below
 * nodes the actor's regions do not reach, where the app role neither reads nor writes a placement.
 * The functions below give the kernel exactly that, as the owner role (SECURITY DEFINER with a
 * fixed search_path), which `placements_owner_write` and `placement_locales_owner_write` let
 * through the forced row level security, and nothing more:
 *
 * - `cms_entry_placement_locales(entry)` lists the released stage of every placement of the entry
 *   in every locale, in the order of the locales and then the placements: its locale, its id, its
 *   version, its visibility, its window and whether it is canonical. It holds no slug and no
 *   presentation, as `cms_placement_locales(entry, locale)` does for one locale.
 * - `cms_placement_close(placement, locale, version, changeset)`, which entry.unpublish closes a
 *   placement with, is created by `2026_10_01_100000_add_node_to_placement_close.php`.
 *
 * Each raises 42501 without an actor context. The functions are created with CREATE OR REPLACE,
 * because migrate:fresh drops tables and leaves functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_entry_placement_locales(uuid)',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('alter table release_log drop constraint release_log_action');
        $connection->statement("alter table release_log add constraint release_log_action check (action in ('released', 'unreleased', 'withdrawn'))");

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_entry_placement_locales(p_entry uuid)
                    returns table (locale text, placement_id uuid, version bigint, visibility text, live_from timestamptz, live_until timestamptz, canonical boolean)
                    language plpgsql stable security definer set search_path = %I, pg_temp as $body$
                    begin
                        if cms_access_actor() is null then
                            raise exception using errcode = $$42501$$, message = $$cms_entry_placement_locales runs only under an actor context$$;
                        end if;

                        return query
                            select pl.locale, pl.placement_id, p.version, pl.visibility, pl.live_from, pl.live_until, pl.canonical
                            from placement_locales pl
                            join placements p on p.id = pl.placement_id
                            where pl.entry_id = p_entry and pl.stage = $$released$$
                            order by pl.locale, pl.placement_id;
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

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));
        $connection->statement('drop function if exists cms_placement_close(uuid, text, bigint, uuid)');
        $connection->statement('alter table release_log drop constraint release_log_action');
        $connection->statement("alter table release_log add constraint release_log_action check (action in ('released', 'withdrawn'))");
    }
};

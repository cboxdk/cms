<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What placement.create and placement.set_window need from the schema (PRD 5.7, 5.9, 5.10, 6.4,
 * invariants 14 and 15), added by the owner role.
 *
 * Reading the structure. `sites` and `site_locales` had no policy and were closed to every role.
 * Every context, the anonymous one included, now reads them (`sites_read`, `site_locales_read`):
 * which sites exist and in which locales they publish is what the public resolves a URL against
 * (PRD 5.9), and it holds no content.
 *
 * Writing the structure. `sites` and `site_locales` are written by a site's registration, as the
 * owner role, as the access fixtures write roles and grants: both get a policy for the role that
 * runs this migration (`<table>_owner_write`), and the app role writes neither. `nodes` and
 * `node_routes` are written by the node commands, as the app role under the call's actor context
 * (the migration that adds them).
 *
 * The placements of an entry across the actor's regions. Placement rights are decided on the
 * placement's node (PRD 5.10), so the app role reads and writes only the placements below the nodes
 * the actor's regions reach. But the canonical placement of an entry in a locale is one across all
 * its placements, on every site (PRD 5.7, invariant 14), and the kernel sets it: a regional editor
 * who opens a window can move the canonical flag off a national placement it cannot reach. The
 * functions below give the kernel exactly that, as the owner role (SECURITY DEFINER with a fixed
 * search_path), which `placements_owner_write` and `placement_locales_owner_write` let through the
 * forced row level security, and nothing more:
 *
 * - `cms_placement_locales(entry, locale)` lists the released stage of every placement of the entry
 *   in the locale: its id, its version, its visibility, its window and whether it is canonical. It
 *   holds no slug and no presentation.
 * - `cms_placement_version(placement)` returns one placement's version, or null when no placement
 *   has the id, so a command on a placement below a node the actor does not reach is refused as
 *   unauthorized rather than answered as if the placement did not exist.
 * - `cms_placement_lock(placement, for_update)` locks one placement's row for the rest of the
 *   transaction and returns its version, or null when no placement has the id, as the commit's
 *   version check needs for every placement the command read: FOR SHARE when the changeset only
 *   read it, FOR NO KEY UPDATE when it changes it.
 * - `cms_placement_canonical(entry, locale)` says whether the entry has a canonical placement in
 *   the locale; the commit reads it after the advisory lock on an entry and locale read without one.
 * - `cms_placement_set_canonical(placement, locale, canonical, version, changeset)` sets or clears
 *   the canonical flag of the placement's released stage in the locale and sets the placement's
 *   version, in the transaction that wrote the changeset, by the context's actor. The placement is
 *   at the version before the one given, or at it when the same changeset changed it already.
 * - `cms_structure_lock_site(site, for_update)` locks one site's row and returns its version, or
 *   null, for the version check of the site a placement is created on.
 *
 * Each raises 42501 without an actor context. The functions are created with CREATE OR REPLACE,
 * because migrate:fresh drops tables and leaves functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the tables the owner writes directly: the sites and their locales */
    private const array STRUCTURE = ['sites', 'site_locales'];

    /** @var list<string> the tables the owner reads and updates in the functions */
    private const array PLACEMENTS = ['placements', 'placement_locales'];

    /** @var list<string> the tables every context reads */
    private const array PUBLIC = ['sites', 'site_locales'];

    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_placement_locales(uuid, text)',
        'cms_placement_version(uuid)',
        'cms_placement_lock(uuid, boolean)',
        'cms_placement_canonical(uuid, text)',
        'cms_placement_set_canonical(uuid, text, boolean, bigint, uuid)',
        'cms_structure_lock_site(uuid, boolean)',
    ];

    /** The check every function starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$%s runs only under an actor context$$;
        end if;
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach ([...self::STRUCTURE, ...self::PLACEMENTS] as $table) {
            $connection->statement(sprintf('create policy %1$s_owner_write on %1$s for all to current_user using (true) with check (true)', $table));
        }

        foreach (self::PUBLIC as $table) {
            $connection->statement(sprintf('create policy %1$s_read on %1$s for select using (cms_access_context() is not null)', $table));
        }

        $this->definer(
            'cms_placement_locales(p_entry uuid, p_locale text) returns table (placement_id uuid, version bigint, visibility text, live_from timestamptz, live_until timestamptz, canonical boolean) language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select pl.placement_id, p.version, pl.visibility, pl.live_from, pl.live_until, pl.canonical
                        from placement_locales pl
                        join placements p on p.id = pl.placement_id
                        where pl.entry_id = p_entry and pl.locale = p_locale and pl.stage = $$released$$
                        order by pl.placement_id;
                end
                SQL,
            'cms_placement_locales',
        );

        $this->definer(
            'cms_placement_version(p_placement uuid) returns bigint language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (select version from placements where id = p_placement);
                end
                SQL,
            'cms_placement_version',
        );

        $this->definer(
            'cms_placement_lock(p_placement uuid, p_for_update boolean) returns bigint language plpgsql volatile',
            <<<'SQL'
                declare
                    v_version bigint;
                begin
                    %s
                    if p_for_update then
                        select version into v_version from placements where id = p_placement for no key update;
                    else
                        select version into v_version from placements where id = p_placement for share;
                    end if;

                    return v_version;
                end
                SQL,
            'cms_placement_lock',
        );

        $this->definer(
            'cms_placement_canonical(p_entry uuid, p_locale text) returns boolean language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return exists (
                        select 1 from placement_locales pl
                        where pl.entry_id = p_entry and pl.locale = p_locale and pl.stage = $$released$$ and pl.canonical
                    );
                end
                SQL,
            'cms_placement_canonical',
        );

        $this->definer(
            'cms_placement_set_canonical(p_placement uuid, p_locale text, p_canonical boolean, p_version bigint, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    %s
                    if not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_placement_set_canonical runs only in the transaction of a changeset by the actor of the context$$;
                    end if;

                    update placements p
                       set version = p_version
                     where p.id = p_placement
                       and p.version in (p_version - 1, p_version);

                    if not found then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$no placement %%%%s is at version %%%%s or %%%%s$$, p_placement, p_version - 1, p_version);
                    end if;

                    update placement_locales pl
                       set canonical = p_canonical
                     where pl.placement_id = p_placement
                       and pl.stage = $$released$$
                       and pl.locale = p_locale;

                    if not found then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$the placement %%%%s has no locale %%%%s$$, p_placement, p_locale);
                    end if;
                end
                SQL,
            'cms_placement_set_canonical',
        );

        $this->definer(
            'cms_structure_lock_site(p_site uuid, p_for_update boolean) returns bigint language plpgsql volatile',
            <<<'SQL'
                declare
                    v_version bigint;
                begin
                    %s
                    if p_for_update then
                        select version into v_version from sites where id = p_site for no key update;
                    else
                        select version into v_version from sites where id = p_site for share;
                    end if;

                    return v_version;
                end
                SQL,
            'cms_structure_lock_site',
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));

        foreach (self::PUBLIC as $table) {
            $connection->statement(sprintf('drop policy %1$s_read on %1$s', $table));
        }

        foreach ([...self::STRUCTURE, ...self::PLACEMENTS] as $table) {
            $connection->statement(sprintf('drop policy %1$s_owner_write on %1$s', $table));
        }
    }

    /**
     * Creates the function as the owner role, SECURITY DEFINER with the search_path fixed to the
     * current schema, with the guard against a missing actor context at the start of its body.
     */
    private function definer(string $signature, string $body, string $name): void
    {
        $body = sprintf($body, sprintf(self::GUARD, $name));

        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL, $signature, str_replace("'", "''", $body)));
    }
};

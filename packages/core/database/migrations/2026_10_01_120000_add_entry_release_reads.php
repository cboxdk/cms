<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The reads placement.set_window needs to keep invariant 6 in the state it stores (PRD 5.7, 6.5):
 * a placement is live, or scheduled to go live, only while its entry is active and the entry's
 * shared variant has a released revision. A placement command is decided on the placement's node
 * (PRD 5.10), and the entry's home may lie outside the actor's regions, so both run as the owner,
 * past the regions, as the other placement functions do, and give only the entry's type, its
 * lifecycle state and the release state and version of its shared head:
 *
 * - `cms_entry_release(entry)` returns one row for an entry that exists: its type, its lifecycle,
 *   and the release state and version of the head of its shared variant, both null without a head;
 *   and no row for an entry that does not exist.
 * - `cms_entry_release_lock(entry, for_update)` locks the entry's row FOR SHARE and the head of its
 *   shared variant FOR SHARE, or FOR NO KEY UPDATE, for the rest of the transaction and returns the
 *   head's version, or null when the entry is not active or has no shared head: the version the
 *   commit checks for the aggregate `entry_release` (Placements\Domain\EntryReleaseRef), so a
 *   release, an unrelease or a revise that commits between a window's read and its commit makes it
 *   version_conflict.
 *
 * Each raises 42501 without an actor context. The functions are created with CREATE OR REPLACE,
 * because migrate:fresh drops tables and leaves functions that do not depend on one.
 *
 * Row level security is forced on `entries` and `variant_heads`, so it holds for the owner too, and
 * until now the owner had no policy on them. Each gets two policies for the owner role alone, the
 * role that runs this migration: `<table>_owner_read` to read every row, and `<table>_owner_lock`,
 * an UPDATE policy whose check is false, because SELECT ... FOR SHARE needs an UPDATE policy that
 * lets it see the row; it lets the owner lock a row and never change one. The app role's policies
 * are unchanged.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_entry_release(uuid)',
        'cms_entry_release_lock(uuid, boolean)',
    ];

    /** @var list<string> the tables the functions read and lock as the owner */
    private const array TABLES = ['entries', 'variant_heads'];

    /** The check every function starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$%s runs only under an actor context$$;
        end if;
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('create policy %1$s_owner_read on %1$s for select to current_user using (true)', $table));
            $connection->statement(sprintf('create policy %1$s_owner_lock on %1$s for update to current_user using (true) with check (false)', $table));
        }

        $this->definer(
            'cms_entry_release(p_entry uuid) returns table (type_id uuid, lifecycle text, release_state text, version bigint) language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select e.type_id, e.lifecycle, h.release_state, h.version
                        from entries e
                        left join variant_heads h on h.entry_id = e.id and h.variant = $$shared$$
                        where e.id = p_entry;
                end
                SQL,
            'cms_entry_release',
        );

        $this->definer(
            'cms_entry_release_lock(p_entry uuid, p_for_update boolean) returns bigint language plpgsql volatile',
            <<<'SQL'
                declare
                    v_lifecycle text;
                    v_version bigint;
                begin
                    %s
                    select lifecycle into v_lifecycle from entries where id = p_entry for share;

                    if p_for_update then
                        select version into v_version from variant_heads where entry_id = p_entry and variant = $$shared$$ for no key update;
                    else
                        select version into v_version from variant_heads where entry_id = p_entry and variant = $$shared$$ for share;
                    end if;

                    if v_lifecycle is distinct from $$active$$ then
                        return null;
                    end if;

                    return v_version;
                end
                SQL,
            'cms_entry_release_lock',
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('drop policy %1$s_owner_lock on %1$s', $table));
            $connection->statement(sprintf('drop policy %1$s_owner_read on %1$s', $table));
        }
    }

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

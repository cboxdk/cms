<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What mount.create and mount.set_override need from the schema (PRD 5.8, 5.10, 6.6 rung 6, 7.13),
 * added by the owner role.
 *
 * Overrides. `mount_overrides` had no policy and was closed to every role. An exception to a mount
 * is decided on the mount's node (PRD 5.8, 5.10), so the app role inserts, replaces and removes a
 * row under the call's actor context only for a mount node the actor's regions reach
 * (`mount_overrides_write`, `mount_overrides_change`, `mount_overrides_remove`, over
 * `cms_access_node(mount_node_id)`). Every context reads the rows (`mount_overrides_read`), the
 * anonymous one included, because path.resolve answers 404 at a mount's URL for an entry the site
 * hides there, and a row holds ids and a flag, no content. Row level security stays forced, so the
 * table holds nothing for anyone without a context.
 *
 * Reading. The node lookup `cms_structure_node(node)` now also returns `mount_source_id`, the
 * source of a mount, which mount.set_override reads to ask what the source shows of the entry; a
 * function's return type cannot be replaced, so it is dropped and created again with the column.
 * One more lookup reads past the actor's regions as the owner role and raises 42501 without an
 * actor context, as the lookups of the node commands do:
 *
 * - `cms_structure_mounted_entry(node, entry, at)` returns one row: whether the entry exists, and
 *   a placement of it below the node, the node itself included, whose released stage is visible at
 *   the time or later, or null. Taking an exception off a mount makes the entry public on the
 *   mount's site when its source shows it (invariant 18), so the kernel must weigh that past the
 *   regions of the actor, who holds no rights on the source.
 *
 * Locking. The commit locks every aggregate a command read and checks its version (PRD 6.2 phase
 * 7), the nodes included, and the node lock read `nodes` as the app role, which `nodes_actor`
 * limits to the actor's regions: a node the command read past the regions, such as the source of
 * a mount, or an ancestor above the region a parent lies in, then read as absent at commit and the
 * command was version_conflict. `cms_structure_lock_nodes(ids, for_update)` locks the rows past
 * the regions as the owner role, FOR SHARE or FOR NO KEY UPDATE, and returns each id with its
 * version, the lookup `cms_identity_lock_actor` is for actors. It takes a row lock and gives a
 * version, nothing more: the commit refuses a plan that changes an aggregate the action did not
 * read, and every node command refuses, before the commit, a node it acts on that the actor does
 * not reach, so no actor changes a node outside its regions through it. A row lock needs an UPDATE
 * policy to pass, so the owner gets `nodes_owner_lock`, USING true and WITH CHECK false: it lets
 * the owner lock any node and write none; the owner's one write, the move, still passes through
 * `nodes_owner_move`.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_structure_mounted_entry(uuid, uuid, timestamptz)',
        'cms_structure_lock_nodes(uuid[], boolean)',
    ];

    /** @var list<string> the policies this migration adds to mount_overrides */
    private const array POLICIES = ['mount_overrides_read', 'mount_overrides_write', 'mount_overrides_change', 'mount_overrides_remove'];

    /** The check every lookup starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$%s runs only under an actor context$$;
        end if;
        SQL;

    /** The body of the node lookup, with and without the mount's source. */
    private const string NODE = <<<'SQL'
        begin
            %s
            return query
                select n.parent_id, n.kind, ltree2text(n.path), n.lifecycle, n.version, cms_access_reaches(n.path)%s
                from nodes n
                where n.id = p_node;
        end
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('create policy mount_overrides_read on mount_overrides for select using (cms_access_context() is not null)');
        $connection->statement('create policy mount_overrides_write on mount_overrides for insert with check (cms_access_node(mount_node_id))');
        $connection->statement('create policy mount_overrides_change on mount_overrides for update using (cms_access_node(mount_node_id)) with check (cms_access_node(mount_node_id))');
        $connection->statement('create policy mount_overrides_remove on mount_overrides for delete using (cms_access_node(mount_node_id))');

        $connection->statement('create policy nodes_owner_lock on nodes for update to current_user using (true) with check (false)');

        $connection->statement('drop function if exists cms_structure_node(uuid)');
        $this->definer(
            'cms_structure_node(p_node uuid) returns table (parent_id uuid, kind text, path text, lifecycle text, version bigint, reachable boolean, mount_source_id uuid) language plpgsql stable',
            sprintf(self::NODE, '%s', ', n.mount_source_id'),
            'cms_structure_node',
        );

        $this->definer(
            'cms_structure_mounted_entry(p_node uuid, p_entry uuid, p_at timestamptz) returns table (found boolean, placement_id uuid) language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select exists (select 1 from entries e where e.id = p_entry),
                               (
                                   select pl.placement_id
                                   from placement_locales pl
                                   join nodes n on n.id = pl.node_id
                                   where pl.entry_id = p_entry
                                     and pl.stage = $$released$$
                                     and pl.visibility not in ($$hidden$$, $$withdrawn$$)
                                     and (pl.live_until is null or pl.live_until > p_at)
                                     and n.path <@ (select path from nodes where id = p_node)
                                   order by pl.placement_id
                                   limit 1
                               );
                end
                SQL,
            'cms_structure_mounted_entry',
        );

        $this->definer(
            'cms_structure_lock_nodes(p_ids uuid[], p_for_update boolean) returns table (id uuid, version bigint) language plpgsql volatile',
            <<<'SQL'
                begin
                    %s
                    if p_for_update then
                        return query select n.id, n.version from nodes n where n.id = any(p_ids) order by n.id for no key update;
                    else
                        return query select n.id, n.version from nodes n where n.id = any(p_ids) order by n.id for share;
                    end if;
                end
                SQL,
            'cms_structure_lock_nodes',
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));
        $connection->statement('drop policy nodes_owner_lock on nodes');
        $connection->statement('drop function cms_structure_node(uuid)');
        $this->definer(
            'cms_structure_node(p_node uuid) returns table (parent_id uuid, kind text, path text, lifecycle text, version bigint, reachable boolean) language plpgsql stable',
            sprintf(self::NODE, '%s', ''),
            'cms_structure_node',
        );

        foreach (array_reverse(self::POLICIES) as $policy) {
            $connection->statement(sprintf('drop policy %s on mount_overrides', $policy));
        }
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path. The
     * body is a literal of the format() that creates it, so its quotes and percent signs are
     * doubled, and the guard is put in before that.
     */
    private function definer(string $signature, string $body, string $name): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            str_replace(["'", '%'], ["''", '%%'], sprintf($body, sprintf(self::GUARD, $name))),
        ));
    }
};

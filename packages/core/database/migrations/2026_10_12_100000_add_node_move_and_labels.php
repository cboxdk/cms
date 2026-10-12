<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What node.move and node.set_label need from the schema (PRD 5.8, 5.10, 6.4, 7.13), added by the
 * owner role.
 *
 * Labels. `node_labels` holds the label of a node per language (node, locale, label), the text a
 * breadcrumb and the panel's tree show for the node: 1 to 200 characters without control
 * characters that start and end with a character that is not white space, as the command checks
 * them. Every context reads them (`node_labels_read`), the anonymous one included, because a
 * breadcrumb is public; the app role inserts and replaces one under the call's actor context only
 * for a node the actor's regions reach (`node_labels_write`, `node_labels_change`), and deletes
 * none. Row level security is forced, so the table holds nothing for anyone without a context.
 *
 * Moving. A move rewrites the path of the node and of every descendant, however many, in one
 * statement, which the app role cannot do under `nodes_actor` alone: a descendant inside an
 * exception of the actor's regions would be left behind, and the tree would no longer be one. The
 * owner function `cms_structure_move_node(node, parent, path, version, changeset)` therefore does
 * it as the owner role, only in the transaction of a node.move changeset by the actor of the
 * context, which `cms_structure_moving()` says and `nodes_owner_move`, the owner's one UPDATE
 * policy on `nodes`, requires: the node goes below the parent on the path at the version given,
 * each descendant keeps its labels below the node's new path and goes one version up, and the
 * function returns how many rows it rewrote. A node still below the old path afterwards, which a
 * node created below a descendant between the statement's snapshot and its row locks would be,
 * raises, so the commit rolls back instead of leaving a node outside its subtree.
 *
 * Reading. Two more lookups read past the actor's regions as the owner role, one row set each, and
 * raise 42501 without an actor context, as the lookups of the node commands do:
 *
 * - `cms_structure_ancestors(node)` returns the ancestors of the node, the root of its tree first,
 *   each as `cms_structure_node` gives a node. A move reads the ancestors of the new parent, so
 *   the commit locks the whole path the node lands under and two moves that would make a cycle
 *   together commit one after the other.
 * - `cms_structure_routed_node(node)` returns the node below the node, itself included, that has a
 *   route, the one nearest the top of the tree and then the lowest id, or null.
 * - `cms_structure_subtree(node, after, limit)` returns the ids of the node and its descendants
 *   after the id given, in id order, at most the limit: the page the invalidation purges a moved
 *   subtree's keys by.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_structure_ancestors(uuid)',
        'cms_structure_routed_node(uuid)',
        'cms_structure_subtree(uuid, uuid, integer)',
        'cms_structure_move_node(uuid, uuid, ltree, bigint, uuid)',
    ];

    /** A locale: a BCP 47 language with optional subtags, as in `site_locales`. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    /** A label: no control character, and a character that is not white space at each end. */
    private const string LABEL = '^[^[:space:][:cntrl:]]([^[:cntrl:]]*[^[:space:][:cntrl:]])?$';

    /** The check every lookup starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$%s runs only under an actor context$$;
        end if;
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;
        $label = self::LABEL;

        $connection->statement(<<<SQL
            create table node_labels (
                node_id uuid not null references nodes (id),
                locale text not null,
                label text not null,
                created_at timestamptz not null,
                updated_at timestamptz not null,
                primary key (node_id, locale),
                constraint node_labels_locale check (locale ~ '{$locale}'),
                constraint node_labels_label check (label ~ '{$label}' and char_length(label) <= 200)
            )
            SQL);
        $connection->statement('alter table node_labels enable row level security');
        $connection->statement('alter table node_labels force row level security');
        $connection->statement('create policy node_labels_read on node_labels for select using (cms_access_context() is not null)');
        $connection->statement('create policy node_labels_write on node_labels for insert with check (cms_access_node(node_id))');
        $connection->statement('create policy node_labels_change on node_labels for update using (cms_access_node(node_id)) with check (cms_access_node(node_id))');
        new TablePrivileges($connection)->limitTo('node_labels', [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);

        $connection->statement(<<<'SQL'
            create or replace function cms_structure_moving() returns boolean language sql stable as $f$
                select exists (
                    select 1 from changesets c
                    where c.actor_id = cms_access_actor()
                      and c.command = 'node.move'
                      and c.xid = pg_current_xact_id()
                )
            $f$
            SQL);
        $connection->statement('create policy nodes_owner_move on nodes for update to current_user using (cms_structure_moving()) with check (cms_structure_moving())');

        $this->definer(
            'cms_structure_ancestors(p_node uuid) returns table (id uuid, parent_id uuid, kind text, path text, lifecycle text, version bigint, reachable boolean) language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select a.id, a.parent_id, a.kind, ltree2text(a.path), a.lifecycle, a.version, cms_access_reaches(a.path)
                        from nodes a
                        where a.path @> (select n.path from nodes n where n.id = p_node)
                          and a.id <> p_node
                        order by nlevel(a.path);
                end
                SQL,
            'cms_structure_ancestors',
        );

        $this->definer(
            'cms_structure_routed_node(p_node uuid) returns uuid language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (
                        select n.id
                        from nodes n
                        where n.path <@ (select s.path from nodes s where s.id = p_node)
                          and exists (select 1 from node_routes r where r.node_id = n.id)
                        order by nlevel(n.path), n.id
                        limit 1
                    );
                end
                SQL,
            'cms_structure_routed_node',
        );

        $this->definer(
            'cms_structure_subtree(p_node uuid, p_after uuid, p_limit integer) returns setof uuid language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return query
                        select n.id
                        from nodes n
                        where n.path <@ (select s.path from nodes s where s.id = p_node)
                          and (p_after is null or n.id > p_after)
                        order by n.id
                        limit p_limit;
                end
                SQL,
            'cms_structure_subtree',
        );

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_structure_move_node(p_node uuid, p_parent uuid, p_path ltree, p_version bigint, p_changeset uuid)
                    returns bigint
                    language plpgsql volatile security definer set search_path = %I, pg_temp as $body$
                    declare
                        v_old ltree;
                        v_moved bigint;
                    begin
                        if cms_access_actor() is null or not exists (
                            select 1 from changesets c
                            where c.changeset_id = p_changeset
                              and c.actor_id = cms_access_actor()
                              and c.command = $$node.move$$
                              and c.xid = pg_current_xact_id()
                        ) then
                            raise exception using
                                errcode = $$42501$$,
                                message = $$cms_structure_move_node runs only in the transaction of a node.move changeset by the actor of the context$$;
                        end if;

                        select n.path into v_old from nodes n where n.id = p_node;

                        if v_old is null then
                            raise exception using
                                errcode = $$P0002$$,
                                message = format($$no node %%s exists to move$$, p_node);
                        end if;

                        update nodes n
                           set path = case when n.id = p_node then p_path else p_path || subpath(n.path, nlevel(v_old)) end,
                               parent_id = case when n.id = p_node then p_parent else n.parent_id end,
                               version = case when n.id = p_node then p_version else n.version + 1 end
                         where n.path <@ v_old;

                        get diagnostics v_moved = row_count;

                        if exists (select 1 from nodes n where n.path <@ v_old) then
                            raise exception using
                                message = format($$a node was created below %%s while it moved, so the move is rolled back; run it again$$, p_node);
                        end if;

                        return v_moved;
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
        $connection->statement('drop policy nodes_owner_move on nodes');
        $connection->statement('drop function cms_structure_moving()');
        $connection->statement('drop table node_labels');
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

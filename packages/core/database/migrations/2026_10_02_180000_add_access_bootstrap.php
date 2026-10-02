<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What the one-time access bootstrap reads (PRD 5.10, 5.16), added by the owner role.
 *
 * `cms_access_bootstrap_state(node, handle)` gives in one row whether any staff actor holds a grant,
 * ended or not, allowing or denying, so the bootstrap is refused once one does; whether a node has
 * the id; and the role with the handle, if one has it, with its ceiling and its permissions, sorted.
 * The app role reads only its own actor's grants and only the nodes its regions reach, so the
 * function runs as the owner role (SECURITY DEFINER, with a fixed search_path and plan_cache_mode =
 * auto, as the access functions have it). It runs only under the actor context of the installation
 * operator (`cms_installation_operator()`), the actor the bootstrap runs as; any other caller gets
 * SQLSTATE 42501 and reads nothing.
 *
 * The function is created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_access_bootstrap_state(p_node uuid, p_handle text) returns table (staff_granted boolean, node_exists boolean, role_id uuid, role_ceiling text, role_permissions text[]) language plpgsql stable security definer set search_path = %I, pg_temp set plan_cache_mode = auto as $body$
                    begin
                        if cms_access_actor() is null or cms_access_actor() is distinct from cms_installation_operator() then
                            raise exception using errcode = $$42501$$, message = $$cms_access_bootstrap_state runs only under the actor context of the installation operator$$;
                        end if;

                        return query
                            select
                                exists (select 1 from grants g join actors a on a.id = g.actor_id where a.actor_class = $$staff$$),
                                exists (select 1 from nodes n where n.id = p_node),
                                r.id,
                                r.classification_ceiling,
                                case when r.id is null then null else coalesce(
                                    (select array_agg(p.command order by p.command) from role_permissions p where p.role_id = r.id),
                                    array[]::text[]
                                ) end
                            from (select 1) as one
                            left join roles r on r.handle = p_handle;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function cms_access_bootstrap_state(uuid, text)');
    }
};

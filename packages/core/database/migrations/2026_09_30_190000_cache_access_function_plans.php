<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lets the access functions of row level security keep their plans (PRD 5.10, 23, GUARDRAILS 4.1).
 *
 * The actor context sets plan_cache_mode to force_custom_plan for the transaction, so the queries
 * that row level security filters are planned with the context's regions (ActorContext). The
 * access functions written in PL/pgSQL (`cms_access_*`, such as cms_access_node and
 * cms_access_entry) run one or more lookups by key for every row a policy checks, and PL/pgSQL
 * plans those lookups through the plan cache, which force_custom_plan makes plan them again on
 * every call: about a millisecond per row checked, so a page of twenty rows or a seed chunk of a
 * few hundred entries spent most of its time planning the same lookups. Each such function gets
 * its own setting, plan_cache_mode = auto, which Postgres applies while the function runs and
 * restores after it, so its lookups use their cached plans and the queries the policies filter are
 * still planned custom. A plan never changes a result, only its cost.
 *
 * An access function a later migration creates or replaces sets the same, `set plan_cache_mode =
 * auto` in its definition; packages/core/tests/Postgres/AccessFunctionPlansTest.php holds every
 * PL/pgSQL access function, and every PL/pgSQL function a policy calls, to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $functions = $connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_proc as p
            join pg_language as l on l.oid = p.prolang
            where p.pronamespace = current_schema()::regnamespace
              and l.lanname = 'plpgsql'
              and p.proname like 'cms\_access\_%'
            order by 1
            SQL);

        foreach ($functions as $function) {
            $signature = is_object($function) && property_exists($function, 'signature') && is_string($function->signature)
                ? $function->signature
                : throw new UnexpectedValueException('An access function has a signature.');

            $connection->statement(sprintf('alter function %s set plan_cache_mode = auto', $signature));
        }
    }
};

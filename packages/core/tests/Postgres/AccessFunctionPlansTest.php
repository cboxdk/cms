<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Illuminate\Support\Facades\DB;

/*
 * The PL/pgSQL functions of row level security keep their plans (PRD 5.10, 23, GUARDRAILS 4.1):
 * the actor context forces custom plans for the transaction, and each access function written in
 * PL/pgSQL, and each PL/pgSQL function a policy calls, sets plan_cache_mode = auto for itself, so
 * its lookups by key use cached plans instead of being planned again for every row a policy checks
 * (migration *_cache_access_function_plans.php).
 */

/**
 * The PL/pgSQL functions of the schema with their settings, by name.
 *
 * @return array<string, list<string>>
 */
function plpgsqlFunctions(): array
{
    $functions = [];

    foreach (DB::connection('pgsql_owner')->select(<<<'SQL'
        select p.proname, coalesce(p.proconfig, '{}'::text[])::text as config
        from pg_proc as p
        join pg_language as l on l.oid = p.prolang
        where p.pronamespace = current_schema()::regnamespace and l.lanname = 'plpgsql'
        SQL) as $function) {
        $name = is_object($function) && property_exists($function, 'proname') ? $function->proname : null;
        $config = is_object($function) && property_exists($function, 'config') ? $function->config : null;

        if (is_string($name) && is_string($config)) {
            $functions[$name] = array_values(array_filter(explode(',', trim($config, '{}'))));
        }
    }

    return $functions;
}

it('sets plan_cache_mode to auto on every PL/pgSQL access function', function (): void {
    $access = array_filter(plpgsqlFunctions(), static fn (string $name): bool => str_starts_with($name, 'cms_access_'), ARRAY_FILTER_USE_KEY);

    expect(array_keys($access))->toContain('cms_access_node', 'cms_access_entry', 'cms_access_revision_home', 'cms_access_released');

    foreach ($access as $name => $config) {
        expect(in_array('plan_cache_mode=auto', $config, true))->toBeTrue($name);
    }
});

it('sets it on every PL/pgSQL function a policy calls', function (): void {
    $functions = plpgsqlFunctions();
    $called = [];

    foreach (DB::connection('pgsql_owner')->select("select coalesce(qual, '') || ' ' || coalesce(with_check, '') as expression from pg_policies where schemaname = current_schema()") as $policy) {
        $expression = is_object($policy) && property_exists($policy, 'expression') && is_string($policy->expression) ? $policy->expression : '';
        preg_match_all('/\b(cms_[a-z_]+)\(/', $expression, $names);

        foreach ($names[1] as $name) {
            $called[$name] = true;
        }
    }

    $plpgsql = array_values(array_filter(array_keys($called), static fn (string $name): bool => isset($functions[$name])));

    expect($plpgsql)->not->toBe([]);

    foreach ($plpgsql as $name) {
        expect(in_array('plan_cache_mode=auto', $functions[$name], true))->toBeTrue($name);
    }
});

<?php

declare(strict_types=1);

use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Illuminate\Support\Facades\DB;

/*
 * PRD 5.10: the CI test that reads as the app role without an actor context and expects no rows.
 * It lists every relation with row level security in the migrated schema from pg_class, so a table
 * a later migration adds, a generated type table or a partition the partition manager creates is
 * covered without a change here. The owner's side writes a row to every kernel table first
 * (AccessWorld, as the superuser and the owner role), so a read of zero rows is the policies'
 * doing, not an empty table.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

it('reads no row as the app role without an actor context, from every table with row level security', function (): void {
    AccessWorld::seed();
    $superuser = StorageTables::superuser();
    $app = DB::connection();

    $relations = StorageTables::texts($superuser, <<<'SQL'
        select c.relname::text || ' ' || c.relispartition::text
            || ' ' || exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'cms_entry_id' and not a.attisdropped)::text as value
        from pg_class c
        where c.relrowsecurity
          and c.relnamespace = any (select oid from pg_namespace where nspname = any (current_schemas(false)))
        order by 1
        SQL);
    $tables = [];
    $partitions = [];

    foreach ($relations as $relation) {
        [$name, $partition, $typeTable] = explode(' ', $relation);

        if ($partition === 'true') {
            $partitions[] = $name;
        } elseif ($typeTable === 'false') {
            $tables[] = $name;
        }
    }

    // Every kernel table has rows, and a table without rows here is a generated type table.
    expect($tables)->toBe(AccessWorld::TABLES)
        ->and($partitions)->toContain('audit_p20260310', 'changesets_p20260310', 'revision_payloads_draft', 'revision_payloads_published_p0000000000000000000');

    foreach ($tables as $table) {
        expect($superuser->table($table)->count())->toBeGreaterThan(0, $table);
    }

    foreach ($relations as $relation) {
        $name = explode(' ', $relation)[0];

        expect($app->scalar("select has_table_privilege(current_user, ?::regclass, 'SELECT')", [$name]))->toBeTrue($name)
            ->and($app->scalar(sprintf('select count(*) from %s', $name)))->toBe(0, $name)
            ->and($app->scalar(sprintf('select count(*) from only %s', $name)))->toBe(0, $name);
    }

    expect(count($relations))->toBeGreaterThan(count($tables));
});

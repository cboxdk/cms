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
 * (AccessWorld, as the superuser and the owner role), and a row of the released, publicly placed
 * entry to each of the workbench's generated type tables (PRD 11.6), which the anonymous context
 * reads, so a read of zero rows is the policies' doing, not an empty table.
 */

/**
 * A row of each workbench type table for the released entry that has a live placement, with a
 * value for every NOT NULL field.
 *
 * @var array<string, array<string, bool|string>>
 */
const TYPE_TABLE_ROWS = [
    'app__fixture_article' => [
        'fixture_featured' => true,
    ],
    'app__fixture_measurement' => [
        'fixture_measured_at' => '2026-03-10 12:00:00+00',
        'fixture_reading' => '12.500',
        'fixture_scale' => 'fixture_celsius',
    ],
];

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
    $typeTables = [];
    $partitions = [];

    foreach ($relations as $relation) {
        [$name, $partition, $typeTable] = explode(' ', $relation);

        if ($partition === 'true') {
            $partitions[] = $name;
        } elseif ($typeTable === 'false') {
            $tables[] = $name;
        } else {
            $typeTables[] = $name;
        }
    }

    foreach (TYPE_TABLE_ROWS as $table => $values) {
        $superuser->table($table)->insert([
            'cms_entry_id' => AccessWorld::ENTRY_PUBLIC,
            'cms_locale' => 'shared',
            'cms_stage' => 'released',
            'cms_home_node' => AccessWorld::ROOT,
            ...$values,
        ]);
    }

    // Every kernel table and every type table of the workbench has rows.
    expect($tables)->toBe(AccessWorld::TABLES)
        ->and($typeTables)->toBe(array_keys(TYPE_TABLE_ROWS))
        ->and($partitions)->toContain('audit_p20260310', 'changesets_p20260310', 'revision_payloads_draft', 'revision_payloads_published_p0000000000000000000');

    foreach ([...$tables, ...$typeTables] as $table) {
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

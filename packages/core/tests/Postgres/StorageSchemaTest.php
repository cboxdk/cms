<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Closure;
use Illuminate\Support\Facades\DB;

/*
 * The structure, entry, placement and mount override tables as the core's migrations leave them
 * (PRD 4.1, 4.2, 5.2 to 5.9), read from the catalog: the ltree extension created by the owner role,
 * the keys, uniques and foreign keys, the GiST index on the node paths, fillfactor 80 on the variant
 * heads and the placement tables, forced row level security with the access migration's policies
 * over the actor context, and the app role's narrowed grants. Rows are written as the superuser,
 * which row level security does not hold; the app role reads and writes none of them without an
 * actor context (AccessPoliciesTest shows the policies with one). The partial unique indexes of invariants 14 and 15 are also shown
 * to refuse as the owner role, in a transaction that lifts the forced row level security of
 * `placement_locales` for the owner and is rolled back.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

it('creates ltree as the owner role, in the schema the tables live in', function (): void {
    $owner = DB::connection('pgsql_owner');

    expect(StorageTables::texts($owner, "select extname::text || ' ' || extnamespace::regnamespace::text || ' ' || extowner::regrole::text as value from pg_extension where extname = 'ltree'"))
        ->toBe(StorageTables::texts($owner, "select 'ltree ' || current_schema()::text || ' ' || current_user::text as value"));
});

it('has the keys, uniques, foreign keys and indexes of the storage form', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = '{'.implode(',', StorageTables::TABLES).'}';

    expect(StorageTables::texts($owner, "select regexp_replace(indexdef, ' ON [a-z0-9_.]+\\.', ' ON ') as value from pg_indexes where tablename = any (?::text[]) and schemaname = current_schema() order by tablename, indexname", [$tables]))->toBe([
        'CREATE INDEX entries_home_node_id ON entries USING btree (home_node_id)',
        'CREATE INDEX entries_owner_actor_id ON entries USING btree (owner_actor_id)',
        'CREATE UNIQUE INDEX entries_pkey ON entries USING btree (id)',
        'CREATE INDEX entries_type_id ON entries USING btree (type_id, id)',
        'CREATE UNIQUE INDEX node_routes_node_key ON node_routes USING btree (node_id, site_id, locale)',
        'CREATE UNIQUE INDEX node_routes_pkey ON node_routes USING btree (site_id, locale, route)',
        'CREATE UNIQUE INDEX nodes_mount_key ON nodes USING btree (id, mount_source_id)',
        'CREATE INDEX nodes_mount_source_id ON nodes USING btree (mount_source_id)',
        'CREATE INDEX nodes_parent_id ON nodes USING btree (parent_id)',
        'CREATE INDEX nodes_path ON nodes USING gist (path)',
        'CREATE UNIQUE INDEX nodes_pkey ON nodes USING btree (id)',
        'CREATE UNIQUE INDEX site_locales_pkey ON site_locales USING btree (site_id, locale)',
        'CREATE UNIQUE INDEX sites_handle_key ON sites USING btree (handle)',
        'CREATE UNIQUE INDEX sites_pkey ON sites USING btree (id)',
        'CREATE UNIQUE INDEX sites_root_node_id_key ON sites USING btree (root_node_id)',
        'CREATE INDEX variant_heads_draft_revision_id ON variant_heads USING btree (draft_revision_id)',
        'CREATE UNIQUE INDEX variant_heads_pkey ON variant_heads USING btree (entry_id, variant)',
        'CREATE INDEX variant_heads_published_revision_id ON variant_heads USING btree (published_revision_id)',
    ])->and(StorageTables::texts($owner, "select conrelid::regclass::text || ' ' || pg_get_constraintdef(oid) as value from pg_constraint where conrelid = any (?::regclass[]) and contype = 'f' order by 1", [$tables]))->toBe([
        'entries FOREIGN KEY (home_node_id) REFERENCES nodes(id)',
        'entries FOREIGN KEY (owner_actor_id) REFERENCES actors(id)',
        'node_routes FOREIGN KEY (node_id) REFERENCES nodes(id)',
        'node_routes FOREIGN KEY (site_id, locale) REFERENCES site_locales(site_id, locale)',
        'nodes FOREIGN KEY (mount_source_id) REFERENCES nodes(id)',
        'nodes FOREIGN KEY (parent_id) REFERENCES nodes(id)',
        'site_locales FOREIGN KEY (site_id) REFERENCES sites(id)',
        'sites FOREIGN KEY (root_node_id) REFERENCES nodes(id)',
        'variant_heads FOREIGN KEY (draft_revision_id) REFERENCES revisions(revision_id)',
        'variant_heads FOREIGN KEY (entry_id) REFERENCES entries(id)',
        'variant_heads FOREIGN KEY (published_revision_id) REFERENCES revisions(revision_id)',
    ])->and(StorageTables::texts($owner, "select c.relname::text || ' ' || coalesce(array_to_string(c.reloptions, ','), '') || ' ' || c.relkind::text as value from pg_class c where c.oid = any (?::regclass[]) order by 1", [$tables]))->toBe([
        'entries  r',
        'node_routes  r',
        'nodes  r',
        'site_locales  r',
        'sites  r',
        'variant_heads fillfactor=80,autovacuum_vacuum_scale_factor=0.01 r',
    ]);
});

it('forces row level security on every table, with the access migration\'s policies over the actor context', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = '{'.implode(',', StorageTables::TABLES).'}';
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = any (?::regclass[]) order by 1", [$tables]))
        ->toBe(array_map(static fn (string $table): string => $table.' true true', StorageTables::TABLES))
        ->and(StorageTables::texts($owner, 'select policyname::text as value from pg_policies where tablename = any (?::text[]) order by 1', [$tables]))
        ->toBe([
            'entries_actor', 'entries_owner_lock', 'entries_owner_read', 'entries_released', 'node_routes_owner_read', 'node_routes_read', 'node_routes_site_root', 'node_routes_write',
            'nodes_actor', 'nodes_granted', 'nodes_owner_read', 'nodes_site_root', 'site_locales_owner_write', 'site_locales_read', 'sites_owner_write', 'sites_read',
            'variant_heads_actor', 'variant_heads_owner_lock', 'variant_heads_owner_read', 'variant_heads_released',
        ])
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);
});

it('narrows the app role to SELECT, INSERT and UPDATE', function (): void {
    $app = DB::connection();

    foreach (StorageTables::TABLES as $table) {
        foreach (['SELECT' => true, 'INSERT' => true, 'UPDATE' => true, 'DELETE' => false, 'TRUNCATE' => false, 'REFERENCES' => false, 'TRIGGER' => false] as $privilege => $held) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe($held, "{$privilege} on {$table}");
        }
    }
});

it('lets the app role read no rows and write none without an actor context, and run no DDL on the tables', function (): void {
    StorageTables::seedEntry();
    $superuser = StorageTables::superuser();
    $app = DB::connection();

    foreach (StorageTables::TABLES as $table) {
        expect($superuser->table($table)->count())->toBeGreaterThan(0, $table)
            ->and($app->table($table)->count())->toBe(0, $table);
    }

    expect(StorageTables::sqlState(fn () => $app->table('nodes')->insert(StorageTables::node('0192a0c0-0000-7000-8000-0000000000ff', kind: 'storage'))))->toBe('42501')
        ->and($app->table('entries')->where('id', StorageTables::ENTRY)->update(['lifecycle' => 'archived']))->toBe(0)
        ->and($superuser->table('entries')->value('lifecycle'))->toBe('active');

    foreach ([
        'create table storage_probe (id bigint)',
        'alter table nodes add column probe text',
        'alter table variant_heads set (fillfactor = 100)',
        'alter table entries disable row level security',
        'create index entries_probe on entries (type_id)',
        'create policy storage_probe on entries for select using (true)',
        'drop table variant_heads',
        'drop extension ltree',
    ] as $ddl) {
        expect(StorageTables::sqlState(fn () => $app->statement($ddl)))->toBe('42501', $ddl);
    }
});

it('keeps each node path ending in the node\'s own id, a root without a parent, and a mount with a source', function (): void {
    StorageTables::seedStructure();
    $superuser = StorageTables::superuser();
    $root = StorageTables::label(StorageTables::ROOT);
    $id = '0192a0c0-0000-7000-8000-000000000040';
    $insert = static fn (array $node): Closure => static fn (): bool => $superuser->table('nodes')->insert($node);

    expect(StorageTables::violation($insert(array_merge(StorageTables::node($id, StorageTables::ROOT, $root), ['path' => $root.'.other']))))->toBe('23514 nodes_path_label')
        ->and(StorageTables::violation($insert(array_merge(StorageTables::node($id), ['path' => $root]))))->toBe('23514 nodes_path_label')
        ->and(StorageTables::violation($insert(array_merge(StorageTables::node($id), ['path' => '']))))->toBe('23514 nodes_path_label')
        ->and(StorageTables::violation($insert(StorageTables::node($id, parentPath: $root))))->toBe('23514 nodes_root')
        ->and(StorageTables::violation($insert(StorageTables::node($id, StorageTables::ROOT))))->toBe('23514 nodes_root')
        ->and(StorageTables::violation($insert(array_merge(StorageTables::node($id, $id, $root), ['path' => $root.'.'.StorageTables::label($id)]))))->toBe('23514 nodes_parent')
        ->and(StorageTables::violation($insert(StorageTables::node($id, StorageTables::ROOT, $root, 'mount'))))->toBe('23514 nodes_mount')
        ->and(StorageTables::violation($insert(StorageTables::node($id, StorageTables::ROOT, $root, 'section', StorageTables::SECTION))))->toBe('23514 nodes_mount')
        ->and(StorageTables::violation($insert(StorageTables::node($id, StorageTables::ROOT, $root, 'mount', $id))))->toBe('23514 nodes_mount_source')
        ->and(StorageTables::violation($insert(StorageTables::node($id, StorageTables::ROOT, $root, 'entry'))))->toBe('23514 nodes_kind')
        ->and(StorageTables::violation($insert(array_merge(StorageTables::node($id, StorageTables::ROOT, $root), ['version' => 0]))))->toBe('23514 nodes_version')
        ->and(StorageTables::violation($insert(StorageTables::node($id, '0192a0c0-0000-7000-8000-0000000000ee', $root))))->toBe('23503 nodes_parent_id_fkey');

    foreach (['site', 'section', 'page', 'list', 'storage'] as $index => $kind) {
        $superuser->table('nodes')->insert(StorageTables::node(sprintf('0192a0c0-0000-7000-8000-00000000006%d', $index), StorageTables::ROOT, $root, $kind));
    }

    $superuser->table('nodes')->insert(StorageTables::node(StorageTables::MOUNT, StorageTables::ROOT, $root, 'mount', StorageTables::SECTION));

    expect($superuser->table('nodes')->where('kind', 'mount')->value('mount_source_id'))->toBe(StorageTables::SECTION)
        ->and($superuser->table('nodes')->count())->toBe(8);
});

it('finds a subtree through the ltree path', function (): void {
    StorageTables::seedStructure();
    $superuser = StorageTables::superuser();
    $section = StorageTables::label(StorageTables::ROOT).'.'.StorageTables::label(StorageTables::SECTION);
    $child = '0192a0c0-0000-7000-8000-000000000041';
    $superuser->table('nodes')->insert(StorageTables::node($child, StorageTables::SECTION, $section, 'list'));

    expect(StorageTables::texts($superuser, 'select id::text as value from nodes where path <@ ?::ltree order by path', [$section]))->toBe([StorageTables::SECTION, $child])
        ->and(StorageTables::texts($superuser, 'select id::text as value from nodes where path @> ?::ltree order by path', [$section]))->toBe([StorageTables::ROOT, StorageTables::SECTION]);
});

it('keeps one site per handle and per root node', function (): void {
    StorageTables::seedStructure();
    $superuser = StorageTables::superuser();
    $site = static fn (array $changes): Closure => static fn (): bool => $superuser->table('sites')->insert(array_merge(
        ['id' => '0192a0c0-0000-7000-8000-000000000011', 'handle' => 'south', 'root_node_id' => StorageTables::SECTION, 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
        $changes,
    ));

    expect(StorageTables::violation($site(['handle' => 'north'])))->toBe('23505 sites_handle_key')
        ->and(StorageTables::violation($site(['root_node_id' => StorageTables::ROOT])))->toBe('23505 sites_root_node_id_key')
        ->and(StorageTables::violation($site(['handle' => 'South'])))->toBe('23514 sites_handle')
        ->and(StorageTables::violation($site(['version' => 0])))->toBe('23514 sites_version')
        ->and(StorageTables::violation($site(['root_node_id' => '0192a0c0-0000-7000-8000-0000000000ee'])))->toBe('23503 sites_root_node_id_fkey');
});

it('keeps routes per site and locale, in a locale of the site, and finds the longest prefix', function (): void {
    StorageTables::seedStructure();
    $superuser = StorageTables::superuser();
    $superuser->table('node_routes')->insert(StorageTables::route(StorageTables::SECTION, '/nyheder'));
    $insert = static fn (array $route): Closure => static fn (): bool => $superuser->table('node_routes')->insert($route);

    // PRD 5.9: every prefix of the path is a candidate, and the longest route found wins.
    $longest = static function (string $path) use ($superuser): mixed {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        $prefixes = ['/'];

        foreach (array_keys($segments) as $count) {
            $prefixes[] = '/'.implode('/', array_slice($segments, 0, $count + 1));
        }

        return $superuser->scalar(
            'select node_id::text from node_routes where site_id = ? and locale = ? and route = any (?::text[]) order by length(route) desc limit 1',
            [StorageTables::SITE, 'da', '{'.implode(',', array_map(static fn (string $prefix): string => '"'.$prefix.'"', $prefixes)).'}'],
        );
    };

    expect($longest('/nyheder/valg/ny-regering'))->toBe(StorageTables::SECTION)
        ->and($longest('/nyheder'))->toBe(StorageTables::SECTION)
        ->and($longest('/sport'))->toBe(StorageTables::ROOT)
        ->and(StorageTables::violation($insert(StorageTables::route(StorageTables::SECTION, '/nyt'))))->toBe('23505 node_routes_node_key')
        ->and(StorageTables::violation($insert(StorageTables::route(StorageTables::ROOT, '/nyheder'))))->toBe('23505 node_routes_pkey')
        ->and(StorageTables::violation($insert(StorageTables::route(StorageTables::SECTION, '/nyheder', 'en'))))->toBe('23503 node_routes_site_locale_fkey')
        ->and(StorageTables::violation($insert(StorageTables::route('0192a0c0-0000-7000-8000-0000000000ee', '/sport'))))->toBe('23503 node_routes_node_id_fkey');

    foreach (['', 'nyheder', '/nyheder/', '//nyheder', '/ny heder'] as $bad) {
        expect(StorageTables::violation($insert(StorageTables::route(StorageTables::SECTION, $bad, 'da'))))->toBe('23514 node_routes_route', $bad);
    }

    $locale = static fn (string $locale): Closure => static fn (): bool => $superuser->table('site_locales')->insert(['site_id' => StorageTables::SITE, 'locale' => $locale, 'created_at' => StorageTables::CREATED_AT]);

    foreach (['Danish', 'da_DK', 'd', 'shared'] as $bad) {
        expect(StorageTables::violation($locale($bad)))->toBe('23514 site_locales_locale', $bad);
    }

    foreach (['en', 'en-GB', 'zh-Hant-TW', 'es-419'] as $good) {
        $locale($good)();
    }

    expect(StorageTables::violation($locale('da')))->toBe('23505 site_locales_pkey')
        ->and($superuser->table('site_locales')->count())->toBe(5);
});

it('keeps one head per variant, shared or a locale, and a released head with its published revision', function (): void {
    StorageTables::seedEntry();
    $superuser = StorageTables::superuser();
    $superuser->table('variant_heads')->insert(StorageTables::head(['variant' => 'en-GB', 'release_state' => 'released', 'published_revision_id' => 2, 'workflow_state' => 'review', 'next_transition_at' => '2026-03-11 06:00:00+00']));
    $superuser->table('variant_heads')->insert(StorageTables::head(['variant' => 'da', 'release_state' => 'withdrawn', 'published_revision_id' => 3]));
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('variant_heads')->insert(StorageTables::head($changes));

    expect(StorageTables::texts($superuser, 'select variant as value from variant_heads order by variant'))->toBe(['da', 'en-GB', 'shared'])
        ->and(StorageTables::violation($insert([])))->toBe('23505 variant_heads_pkey');

    foreach ([
        'variant_heads_variant' => ['variant' => 'Shared'],
        'variant_heads_released' => ['variant' => 'en', 'release_state' => 'released'],
        'variant_heads_release_state' => ['variant' => 'en', 'release_state' => 'published'],
        'variant_heads_schema_version' => ['variant' => 'en', 'schema_version' => 0],
        'variant_heads_version' => ['variant' => 'en', 'version' => 0],
        'variant_heads_draft_revision' => ['variant' => 'en', 'draft_revision_id' => 0],
        'variant_heads_published_revision' => ['variant' => 'en', 'published_revision_id' => 0],
        'variant_heads_workflow_state' => ['variant' => 'en', 'workflow_state' => 'In review'],
    ] as $constraint => $changes) {
        expect(StorageTables::violation($insert($changes)))->toBe('23514 '.$constraint, (string) json_encode($changes));
    }

    expect(StorageTables::violation($insert(['variant' => 'english'])))->toBe('23514 variant_heads_variant')
        ->and(StorageTables::violation($insert(['entry_id' => '0192a0c0-0000-7000-8000-0000000000ee'])))->toBe('23503 variant_heads_entry_id_fkey');
});

it('keeps an entry\'s lifecycle to its states, and its home and owner to existing rows', function (): void {
    StorageTables::seedStructure();
    $superuser = StorageTables::superuser();
    $other = '0192a0c0-0000-7000-8000-000000000021';
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('entries')->insert(array_merge(StorageTables::entry(), $changes));

    expect(StorageTables::violation($insert(['lifecycle' => 'draft'])))->toBe('23514 entries_lifecycle')
        ->and(StorageTables::violation($insert(['version' => 0])))->toBe('23514 entries_version')
        ->and(StorageTables::violation($insert(['home_node_id' => $other])))->toBe('23503 entries_home_node_id_fkey')
        ->and(StorageTables::violation($insert(['owner_actor_id' => $other])))->toBe('23503 entries_owner_actor_id_fkey');

    foreach (['active', 'archived', 'merged', 'tombstoned', 'purged'] as $index => $lifecycle) {
        $superuser->table('entries')->insert(array_merge(StorageTables::entry(sprintf('0192a0c0-0000-7000-8000-00000000005%d', $index)), ['lifecycle' => $lifecycle]));
    }

    expect($superuser->table('entries')->count())->toBe(5);
});

it('has the keys, uniques, foreign keys, partial unique indexes and storage options of the placement tables', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = '{'.implode(',', StorageTables::PLACEMENT_TABLES).'}';

    expect(StorageTables::texts($owner, "select regexp_replace(indexdef, ' ON [a-z0-9_.]+\\.', ' ON ') as value from pg_indexes where tablename = any (?::text[]) and schemaname = current_schema() order by tablename, indexname", [$tables]))->toBe([
        'CREATE INDEX mount_overrides_entry_id ON mount_overrides USING btree (entry_id)',
        'CREATE INDEX mount_overrides_mount ON mount_overrides USING btree (mount_node_id, source_node_id)',
        'CREATE UNIQUE INDEX mount_overrides_pkey ON mount_overrides USING btree (mount_node_id, entry_id)',
        'CREATE INDEX placement_generations_node_id ON placement_generations USING btree (node_id)',
        'CREATE UNIQUE INDEX placement_generations_node_key ON placement_generations USING btree (placement_id, stage, node_id)',
        'CREATE UNIQUE INDEX placement_generations_pkey ON placement_generations USING btree (placement_id, stage)',
        'CREATE UNIQUE INDEX placement_locales_canonical_key ON placement_locales USING btree (entry_id, locale, stage) WHERE canonical',
        'CREATE INDEX placement_locales_entry ON placement_locales USING btree (entry_id, placement_id)',
        'CREATE INDEX placement_locales_generation ON placement_locales USING btree (placement_id, stage, node_id)',
        'CREATE UNIQUE INDEX placement_locales_pkey ON placement_locales USING btree (placement_id, stage, locale)',
        "CREATE UNIQUE INDEX placement_locales_slug_key ON placement_locales USING btree (node_id, locale, slug, stage) WHERE (visibility <> 'withdrawn'::text)",
        "CREATE INDEX placement_locales_withdrawn_slug ON placement_locales USING btree (node_id, locale, slug, stage) WHERE (visibility = 'withdrawn'::text)",
        'CREATE UNIQUE INDEX placements_entry_key ON placements USING btree (entry_id, id)',
        'CREATE UNIQUE INDEX placements_pkey ON placements USING btree (id)',
    ])->and(StorageTables::texts($owner, "select conrelid::regclass::text || ' ' || pg_get_constraintdef(oid) as value from pg_constraint where conrelid = any (?::regclass[]) and contype = 'f' order by 1", [$tables]))->toBe([
        'mount_overrides FOREIGN KEY (entry_id) REFERENCES entries(id)',
        'mount_overrides FOREIGN KEY (mount_node_id, source_node_id) REFERENCES nodes(id, mount_source_id) ON UPDATE CASCADE',
        'placement_generations FOREIGN KEY (node_id) REFERENCES nodes(id)',
        'placement_generations FOREIGN KEY (placement_id) REFERENCES placements(id)',
        'placement_locales FOREIGN KEY (entry_id, placement_id) REFERENCES placements(entry_id, id)',
        'placement_locales FOREIGN KEY (placement_id, stage, node_id) REFERENCES placement_generations(placement_id, stage, node_id) ON UPDATE CASCADE',
        'placements FOREIGN KEY (entry_id) REFERENCES entries(id)',
    ])->and(StorageTables::texts($owner, "select c.relname::text || ' ' || coalesce(array_to_string(c.reloptions, ','), '') || ' ' || c.relkind::text as value from pg_class c where c.oid = any (?::regclass[]) order by 1", [$tables]))->toBe([
        'mount_overrides  r',
        'placement_generations fillfactor=80,autovacuum_vacuum_scale_factor=0.01 r',
        'placement_locales fillfactor=80,autovacuum_vacuum_scale_factor=0.01 r',
        'placements fillfactor=80,autovacuum_vacuum_scale_factor=0.01 r',
    ]);
});

it('forces row level security on the placement tables, with the access migration\'s policies, and narrows the app role\'s grants', function (): void {
    $owner = DB::connection('pgsql_owner');
    $app = DB::connection();
    $tables = '{'.implode(',', StorageTables::PLACEMENT_TABLES).'}';
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = any (?::regclass[]) order by 1", [$tables]))
        ->toBe(array_map(static fn (string $table): string => $table.' true true', StorageTables::PLACEMENT_TABLES))
        ->and(StorageTables::texts($owner, 'select policyname::text as value from pg_policies where tablename = any (?::text[]) order by 1', [$tables]))
        ->toBe([
            'placement_generations_actor', 'placement_generations_released', 'placement_locales_actor', 'placement_locales_owner_write',
            'placement_locales_released', 'placements_actor', 'placements_owner_write', 'placements_released', 'placements_write',
        ])
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);

    foreach (StorageTables::PLACEMENT_TABLES as $table) {
        foreach (['SELECT' => true, 'INSERT' => true, 'UPDATE' => true, 'DELETE' => $table !== 'placements', 'TRUNCATE' => false, 'REFERENCES' => false, 'TRIGGER' => false] as $privilege => $held) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe($held, "{$privilege} on {$table}");
        }
    }
});

it('lets the app role read and write no placement rows without an actor context', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $app = DB::connection();

    foreach (StorageTables::PLACEMENT_TABLES as $table) {
        expect($superuser->table($table)->count())->toBe(1, $table)
            ->and($app->table($table)->count())->toBe(0, $table);
    }

    expect(StorageTables::sqlState(fn () => $app->table('placements')->insert(StorageTables::placement('0192a0c0-0000-7000-8000-000000000071'))))->toBe('42501')
        ->and($app->table('placement_locales')->update(['visibility' => 'hidden']))->toBe(0)
        ->and($app->table('mount_overrides')->delete())->toBe(0)
        ->and($superuser->table('placement_locales')->value('visibility'))->toBe('live')
        ->and($superuser->table('mount_overrides')->count())->toBe(1);
});

it('refuses, as the owner role, a second non-withdrawn placement with the same slug under a node and a second canonical placement of an entry and locale', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $second = '0192a0c0-0000-7000-8000-000000000071';
    $superuser->table('placements')->insert(StorageTables::placement($second));
    $superuser->table('placement_generations')->insert(StorageTables::generation($second));

    // The owner role passes the table's row level security only while it is not forced, so each
    // attempt lifts the force in a transaction of its own and rolls it back.
    $asOwner = static function (array $changes): string {
        $owner = DB::connection('pgsql_owner');
        $owner->beginTransaction();

        try {
            $owner->statement('alter table placement_locales no force row level security');

            return StorageTables::violation(static fn (): bool => $owner->table('placement_locales')->insert(StorageTables::placementLocale($changes)));
        } finally {
            $owner->rollBack();
        }
    };

    expect($asOwner(['placement_id' => $second, 'canonical' => false]))->toBe('23505 placement_locales_slug_key')
        ->and($asOwner(['placement_id' => $second, 'slug' => 'valg-2']))->toBe('23505 placement_locales_canonical_key')
        ->and(DB::connection('pgsql_owner')->scalar("select relforcerowsecurity from pg_class where oid = 'placement_locales'::regclass"))->toBeTrue()
        ->and($superuser->table('placement_locales')->count())->toBe(1);
});

it('keeps (node, locale, slug) unique among the placements that are not withdrawn, per stage', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $second = '0192a0c0-0000-7000-8000-000000000071';
    $superuser->table('placements')->insert(StorageTables::placement($second));
    $superuser->table('placement_generations')->insert(StorageTables::generation($second));
    $superuser->table('placement_generations')->insert(StorageTables::generation($second, 'draft'));
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('placement_locales')->insert(StorageTables::placementLocale(array_merge(['placement_id' => $second, 'canonical' => false], $changes)));

    expect(StorageTables::violation($insert([])))->toBe('23505 placement_locales_slug_key')
        ->and(StorageTables::violation($insert(['visibility' => 'hidden'])))->toBe('23505 placement_locales_slug_key');

    $insert(['locale' => 'en'])();
    $insert(['stage' => 'draft'])();
    $superuser->table('placement_locales')->where('placement_id', StorageTables::PLACEMENT)->update(['visibility' => 'withdrawn', 'canonical' => false]);
    $insert([])();

    expect(StorageTables::violation(static fn (): int => $superuser->table('placement_locales')->where('placement_id', StorageTables::PLACEMENT)->update(['visibility' => 'hidden'])))->toBe('23505 placement_locales_slug_key')
        ->and(StorageTables::texts($superuser, "select placement_id::text || ' ' || stage || ' ' || locale || ' ' || visibility as value from placement_locales order by placement_id, stage, locale"))->toBe([
            StorageTables::PLACEMENT.' released da withdrawn',
            $second.' draft da live',
            $second.' released da live',
            $second.' released en live',
        ]);
});

it('allows at most one canonical placement per entry and locale, per stage, and never a withdrawn one', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $second = '0192a0c0-0000-7000-8000-000000000071';
    $superuser->table('placements')->insert(StorageTables::placement($second));
    $superuser->table('placement_generations')->insert(StorageTables::generation($second));
    $superuser->table('placement_generations')->insert(StorageTables::generation($second, 'staged'));
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('placement_locales')->insert(StorageTables::placementLocale(array_merge(['placement_id' => $second, 'slug' => 'valg-2'], $changes)));

    expect(StorageTables::violation($insert([])))->toBe('23505 placement_locales_canonical_key')
        ->and(StorageTables::violation($insert(['visibility' => 'hidden'])))->toBe('23505 placement_locales_canonical_key');

    $insert(['locale' => 'en'])();
    $insert(['stage' => 'staged'])();
    $insert(['canonical' => false])();

    expect(StorageTables::violation(static fn (): int => $superuser->table('placement_locales')->where('placement_id', StorageTables::PLACEMENT)->update(['visibility' => 'withdrawn'])))->toBe('23514 placement_locales_canonical')
        ->and($superuser->table('placement_locales')->where('canonical', true)->count())->toBe(3);
});

it('keeps a placement locale\'s stage, locale, slug, visibility and window to their rules, and to its placement\'s entry and generation', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $superuser->table('placement_generations')->insert(StorageTables::generation(stage: 'draft'));
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('placement_locales')->insert(StorageTables::placementLocale(array_merge(['stage' => 'draft', 'canonical' => false], $changes)));

    foreach ([
        'placement_locales_locale' => [['locale' => 'Danish'], ['locale' => 'shared'], ['locale' => 'da_DK']],
        'placement_locales_slug' => [['slug' => ''], ['slug' => 'a/b'], ['slug' => 'a b'], ['slug' => '.'], ['slug' => '..']],
        'placement_locales_visibility' => [['visibility' => 'published'], ['visibility' => 'Live']],
        'placement_locales_window' => [['live_from' => '2026-03-11 06:00:00+00', 'live_until' => '2026-03-11 06:00:00+00'], ['live_from' => '2026-03-11 06:00:00+00', 'live_until' => '2026-03-10 06:00:00+00']],
        'placement_locales_scheduled' => [['visibility' => 'scheduled', 'live_until' => '2026-03-12 06:00:00+00']],
        'placement_locales_expired' => [['visibility' => 'expired', 'live_from' => '2026-03-09 06:00:00+00']],
        'placement_locales_canonical' => [['visibility' => 'withdrawn', 'canonical' => true]],
    ] as $constraint => $cases) {
        foreach ($cases as $changes) {
            expect(StorageTables::violation($insert($changes)))->toBe('23514 '.$constraint, (string) json_encode($changes));
        }
    }

    expect(StorageTables::violation($insert(['entry_id' => '0192a0c0-0000-7000-8000-000000000021'])))->toBe('23503 placement_locales_placement_fkey')
        ->and(StorageTables::violation($insert(['node_id' => StorageTables::ROOT])))->toBe('23503 placement_locales_generation_fkey')
        ->and(StorageTables::violation($insert(['stage' => 'staged'])))->toBe('23503 placement_locales_generation_fkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('placement_generations')->insert(StorageTables::generation(stage: 'published'))))->toBe('23514 placement_generations_stage')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('placement_generations')->insert(StorageTables::generation(stage: 'draft'))))->toBe('23505 placement_generations_pkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('placement_generations')->insert(StorageTables::generation(stage: 'staged', node: '0192a0c0-0000-7000-8000-0000000000ee'))))->toBe('23503 placement_generations_node_id_fkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('placements')->insert(array_merge(StorageTables::placement('0192a0c0-0000-7000-8000-000000000071'), ['version' => 0]))))->toBe('23514 placements_version')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('placements')->insert(StorageTables::placement('0192a0c0-0000-7000-8000-000000000071', '0192a0c0-0000-7000-8000-0000000000ee'))))->toBe('23503 placements_entry_id_fkey');

    foreach ([
        ['visibility' => 'hidden'],
        ['visibility' => 'scheduled', 'live_from' => '2026-03-11 06:00:00+00', 'next_transition_at' => '2026-03-11 06:00:00+00', 'locale' => 'en'],
        ['visibility' => 'live', 'live_until' => '2026-03-12 06:00:00+00', 'next_transition_at' => '2026-03-12 06:00:00+00', 'locale' => 'en-GB'],
        ['visibility' => 'expired', 'live_from' => '2026-03-09 06:00:00+00', 'live_until' => '2026-03-10 06:00:00+00', 'locale' => 'sv'],
        ['visibility' => 'withdrawn', 'locale' => 'nb'],
    ] as $changes) {
        $insert($changes)();
    }

    expect($superuser->table('placement_locales')->where('stage', 'draft')->count())->toBe(5);
});

it('moves a placement by changing its generation\'s node, and its locales follow', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $list = '0192a0c0-0000-7000-8000-000000000042';
    $superuser->table('nodes')->insert(StorageTables::node($list, StorageTables::ROOT, StorageTables::label(StorageTables::ROOT), 'list'));

    $superuser->table('placement_generations')->where('placement_id', StorageTables::PLACEMENT)->update(['node_id' => $list]);

    expect($superuser->table('placement_locales')->value('node_id'))->toBe($list)
        ->and($superuser->table('placements')->value('id'))->toBe(StorageTables::PLACEMENT)
        ->and(StorageTables::violation(static fn (): int => $superuser->table('placement_locales')->update(['node_id' => StorageTables::SECTION])))->toBe('23503 placement_locales_generation_fkey');
});

it('keeps a mount override on a mount node, with the mount\'s source, one per entry, and with an effect', function (): void {
    StorageTables::seedPlacement();
    $superuser = StorageTables::superuser();
    $other = '0192a0c0-0000-7000-8000-000000000051';
    $superuser->table('entries')->insert(StorageTables::entry($other));
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('mount_overrides')->insert(StorageTables::mountOverride($changes));

    expect(StorageTables::violation($insert([])))->toBe('23505 mount_overrides_pkey')
        ->and(StorageTables::violation($insert(['mount_node_id' => StorageTables::SECTION, 'source_node_id' => StorageTables::ROOT, 'entry_id' => $other])))->toBe('23503 mount_overrides_mount_fkey')
        ->and(StorageTables::violation($insert(['source_node_id' => StorageTables::ROOT, 'entry_id' => $other])))->toBe('23503 mount_overrides_mount_fkey')
        ->and(StorageTables::violation($insert(['entry_id' => '0192a0c0-0000-7000-8000-0000000000ee'])))->toBe('23503 mount_overrides_entry_id_fkey')
        ->and(StorageTables::violation($insert(['entry_id' => $other, 'hidden' => false])))->toBe('23514 mount_overrides_effect')
        ->and(StorageTables::violation($insert(['entry_id' => $other, 'version' => 0])))->toBe('23514 mount_overrides_version');

    $insert(['entry_id' => $other, 'hidden' => false, 'priority_cap' => 3])();
    $list = '0192a0c0-0000-7000-8000-000000000042';
    $superuser->table('nodes')->insert(StorageTables::node($list, StorageTables::ROOT, StorageTables::label(StorageTables::ROOT), 'list'));
    $superuser->table('nodes')->where('id', StorageTables::MOUNT)->update(['mount_source_id' => $list]);

    expect(StorageTables::texts($superuser, "select entry_id::text || ' ' || source_node_id::text || ' ' || hidden::text || ' ' || coalesce(priority_cap::text, '-') as value from mount_overrides order by entry_id"))->toBe([
        StorageTables::ENTRY.' '.$list.' true -',
        $other.' '.$list.' false 3',
    ]);
});

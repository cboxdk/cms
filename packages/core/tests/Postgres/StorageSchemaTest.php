<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Closure;
use Illuminate\Support\Facades\DB;

/*
 * The structure and entry tables as the core's migrations leave them (PRD 4.1, 4.2, 5.2 to 5.9),
 * read from the catalog: the ltree extension created by the owner role, the keys, uniques and
 * foreign keys, the GiST index on the node paths, fillfactor 80 on the variant heads, forced row
 * level security without a policy, and the app role's narrowed grants. Rows are written as the
 * superuser, the one role their row level security lets in while they have no policy.
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
        'CREATE UNIQUE INDEX node_routes_node_key ON node_routes USING btree (node_id, site_id, locale)',
        'CREATE UNIQUE INDEX node_routes_pkey ON node_routes USING btree (site_id, locale, route)',
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

it('forces row level security on every table and gives none of them a policy yet', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = '{'.implode(',', StorageTables::TABLES).'}';
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = any (?::regclass[]) order by 1", [$tables]))
        ->toBe(array_map(static fn (string $table): string => $table.' true true', StorageTables::TABLES))
        ->and($owner->scalar('select count(*) from pg_policies where tablename = any (?::text[])', [$tables]))->toBe(0)
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

it('lets the app role read no rows and write none, and run no DDL on the tables', function (): void {
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

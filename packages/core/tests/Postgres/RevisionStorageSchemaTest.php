<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * The changeset, revision, head snapshot and release log tables as the core's migrations leave
 * them (PRD 4.1, 4.2, 5.4 to 5.6, 6.1), read from the catalog: the narrow registers and their keys,
 * the two-level partitioning of the revision payloads, the changesets partitioned per day, forced
 * row level security with the access migration's policies over the actor context, the app role's
 * narrowed grants and the constraints. Rows are written as the superuser, which row level security
 * does not hold; the app role reads and writes none of them without an actor context. The partitions of changesets are covered for 2026-03-10, the day of the changeset ids.
 */

/** @var list<string> the tables of this storage form that are not partitions, sorted */
const REVISION_STORAGE_TABLES = [
    'changeset_principals',
    'changeset_reason_texts',
    'changeset_register',
    'changesets',
    'head_snapshots',
    'release_log',
    'revision_payloads',
    'revisions',
];

const REVISION_STORAGE_ACTOR = '019cd79e-4600-7000-8000-000000000080';

const REVISION_STORAGE_OTHER_CHANGESET = '019cd79e-4600-7000-8000-000000000071';

beforeEach(function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-03-10T00:00:00Z'), new DateTimeImmutable('2026-03-10T23:59:59Z'));
});

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * The seeded entry and its revisions, the acting actor and the metadata of the seeded changeset,
 * written as the superuser.
 */
function seedChangeset(): void
{
    StorageTables::seedEntry();
    $superuser = StorageTables::superuser();
    $superuser->table('actors')->insert(['id' => REVISION_STORAGE_ACTOR, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => StorageTables::CREATED_AT]);
    $superuser->table('changesets')->insert(changesetRow());
}

/**
 * @param  array<mixed>  $changes
 * @return array<mixed>
 */
function changesetRow(array $changes = []): array
{
    return array_merge([
        'changeset_id' => StorageTables::CHANGESET,
        'command' => 'entry.revise',
        'command_version' => 1,
        'actor_id' => REVISION_STORAGE_ACTOR,
        'issuer_kind' => 'human',
        'surface' => 'rest',
        'reason_code' => null,
        'idempotency_key' => 'key-1',
        'correlation_id' => 'trace-1',
        'provenance_model' => null,
        'provenance_model_version' => null,
        'provenance_parameters' => null,
        'provenance_prompt' => null,
        'provenance_sources' => null,
        'format_version' => 1,
        'created_at' => StorageTables::CREATED_AT,
    ], $changes);
}

/**
 * The regclass array of the tables, for a catalog query.
 *
 * @param  list<string>  $tables
 */
function tableArray(array $tables): string
{
    return '{'.implode(',', $tables).'}';
}

it('partitions changesets and their principals by range on changeset_id, and the payloads by list on kind and then by range on revision_id', function (): void {
    $owner = DB::connection('pgsql_owner');

    expect(StorageTables::texts($owner, <<<'SQL'
        select c.relname::text || ' ' || p.partstrat::text || ' ' || (
            select string_agg(a.attname::text, ',' order by a.attnum) from pg_attribute a where a.attrelid = c.oid and a.attnum = any (p.partattrs::int2[])
        ) || ' ' || coalesce(pg_get_expr(c.relpartbound, c.oid), 'root') as value
        from pg_partitioned_table p
        join pg_class c on c.oid = p.partrelid
        where c.relname in ('changesets', 'changeset_principals', 'revision_payloads', 'revision_payloads_draft', 'revision_payloads_published')
        order by 1
        SQL))->toBe([
        'changeset_principals r changeset_id root',
        'changesets r changeset_id root',
        'revision_payloads l kind root',
        "revision_payloads_draft r revision_id FOR VALUES IN ('draft')",
        "revision_payloads_published r revision_id FOR VALUES IN ('published')",
    ])->and(StorageTables::texts($owner, "select relname::text || ' ' || relkind::text as value from pg_class where oid = any (?::regclass[]) order by 1", [tableArray(REVISION_STORAGE_TABLES)]))->toBe([
        'changeset_principals p',
        'changeset_reason_texts r',
        'changeset_register r',
        'changesets p',
        'head_snapshots r',
        'release_log r',
        'revision_payloads p',
        'revisions r',
    ])->and(StorageTables::texts($owner, "select inhrelid::regclass::text as value from pg_inherits where inhparent = 'changesets'::regclass order by 1"))->toContain('changesets_p20260310')
        ->and(StorageTables::texts($owner, "select inhrelid::regclass::text as value from pg_inherits where inhparent = 'changeset_principals'::regclass order by 1"))->toContain('changeset_principals_p20260310')
        ->and(StorageTables::texts($owner, "select pg_get_expr(relpartbound, oid) as value from pg_class where relname = 'changesets_p20260310'"))->toBe(["FOR VALUES FROM ('019cd50b-1800-7000-8000-000000000000') TO ('019cda31-7400-7000-8000-000000000000')"]);
});

it('has the keys, uniques, foreign keys and indexes of the storage form', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = tableArray(REVISION_STORAGE_TABLES);

    expect(StorageTables::texts($owner, "select regexp_replace(indexdef, ' ON (ONLY )?[a-z0-9_.]+\\.', ' ON ') as value from pg_indexes where tablename = any (?::text[]) and schemaname = current_schema() order by tablename, indexname", [$tables]))->toBe([
        'CREATE INDEX changeset_principals_actor_id ON changeset_principals USING btree (actor_id)',
        'CREATE UNIQUE INDEX changeset_principals_pkey ON changeset_principals USING btree (changeset_id, "position")',
        'CREATE UNIQUE INDEX changeset_reason_texts_pkey ON changeset_reason_texts USING btree (changeset_id)',
        'CREATE UNIQUE INDEX changeset_register_pkey ON changeset_register USING btree (changeset_id)',
        'CREATE INDEX changesets_actor_id ON changesets USING btree (actor_id)',
        'CREATE UNIQUE INDEX changesets_pkey ON changesets USING btree (changeset_id)',
        'CREATE UNIQUE INDEX head_snapshots_pkey ON head_snapshots USING btree (entry_id, variant)',
        'CREATE INDEX release_log_changeset_id ON release_log USING btree (changeset_id)',
        'CREATE INDEX release_log_head_effective_at ON release_log USING btree (entry_id, variant, effective_at)',
        'CREATE UNIQUE INDEX release_log_pkey ON release_log USING btree (release_id)',
        'CREATE INDEX release_log_revision_id ON release_log USING btree (revision_id)',
        'CREATE UNIQUE INDEX revision_payloads_pkey ON revision_payloads USING btree (revision_id, kind)',
        'CREATE INDEX revisions_changeset_id ON revisions USING btree (changeset_id)',
        'CREATE UNIQUE INDEX revisions_entry_variant_rev_no_key ON revisions USING btree (entry_id, variant, rev_no)',
        'CREATE UNIQUE INDEX revisions_pkey ON revisions USING btree (revision_id)',
    ])->and(StorageTables::texts($owner, "select conrelid::regclass::text || ' ' || pg_get_constraintdef(oid) as value from pg_constraint where conrelid = any (?::regclass[]) and contype = 'f' order by 1", [$tables]))->toBe([
        'changeset_principals FOREIGN KEY (actor_id) REFERENCES actors(id)',
        'changeset_principals FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'changeset_reason_texts FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'changesets FOREIGN KEY (actor_id) REFERENCES actors(id)',
        'changesets FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'head_snapshots FOREIGN KEY (entry_id, variant) REFERENCES variant_heads(entry_id, variant)',
        'release_log FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'release_log FOREIGN KEY (entry_id, variant) REFERENCES variant_heads(entry_id, variant)',
        'release_log FOREIGN KEY (revision_id) REFERENCES revisions(revision_id)',
        'revisions FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'revisions FOREIGN KEY (entry_id) REFERENCES entries(id)',
    ])->and(StorageTables::texts($owner, "select relname::text || ' ' || coalesce(array_to_string(reloptions, ','), '-') as value from pg_class where oid = any (?::regclass[]) order by 1", [$tables]))->toBe([
        'changeset_principals -',
        'changeset_reason_texts -',
        'changeset_register -',
        'changesets -',
        'head_snapshots fillfactor=80,autovacuum_vacuum_scale_factor=0.01',
        'release_log -',
        'revision_payloads -',
        'revisions -',
    ])->and(StorageTables::texts($owner, "select c.relname::text || ' ' || d.refobjid::regclass::text || '.' || a.attname::text as value from pg_class c join pg_depend d on d.objid = c.oid and d.deptype = 'a' join pg_attribute a on a.attrelid = d.refobjid and a.attnum = d.refobjsubid where c.relkind = 'S' and c.relname in ('revisions_revision_id_seq', 'release_log_release_id_seq') order by 1"))->toBe([
        'release_log_release_id_seq release_log.release_id',
        'revisions_revision_id_seq revisions.revision_id',
    ]);
});

it('forces row level security on every table, its partitions included, with the access migration\'s policies on the tables and none on the partitions', function (): void {
    $owner = DB::connection('pgsql_owner');
    $all = StorageTables::texts($owner, <<<'SQL'
        select c.relname::text as value
        from pg_class c
        where c.relname in ('changesets', 'changeset_principals', 'revision_payloads')
           or c.oid in (select inhrelid from pg_inherits where inhparent in ('changesets'::regclass, 'changeset_principals'::regclass, 'revision_payloads'::regclass, 'revision_payloads_draft'::regclass, 'revision_payloads_published'::regclass))
           or c.relname = any (?::text[])
        order by 1
        SQL, [tableArray(REVISION_STORAGE_TABLES)]);
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect($all)->toContain('changesets_p20260310', 'changeset_principals_p20260310', 'revision_payloads_draft', 'revision_payloads_published', 'revision_payloads_draft_p0000000000000000000', 'revision_payloads_published_p0000000000010000000')
        ->and(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname = any (?::text[]) order by 1", [tableArray($all)]))
        ->toBe(array_map(static fn (string $table): string => $table.' true true', $all))
        ->and(StorageTables::texts($owner, 'select policyname::text as value from pg_policies where tablename = any (?::text[]) order by 1', [tableArray($all)]))->toBe([
            'changeset_principals_actor', 'changeset_principals_write', 'changeset_reason_texts_actor', 'changeset_reason_texts_write',
            'changeset_register_write', 'changesets_actor', 'changesets_write', 'head_snapshots_actor', 'head_snapshots_released',
            'release_log_actor', 'revision_payloads_actor', 'revision_payloads_released', 'revisions_actor', 'revisions_released',
        ])
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);
});

it('narrows the app role to SELECT and INSERT, and UPDATE on the head snapshots alone, on every level of the partitioned tables', function (): void {
    $app = DB::connection();
    $update = ['head_snapshots'];

    foreach ([...REVISION_STORAGE_TABLES, 'revision_payloads_draft', 'revision_payloads_published_p0000000000000000000', 'changesets_p20260310', 'changeset_principals_p20260310'] as $table) {
        foreach (['SELECT' => true, 'INSERT' => true, 'UPDATE' => in_array($table, $update, true), 'DELETE' => false, 'TRUNCATE' => false, 'REFERENCES' => false, 'TRIGGER' => false] as $privilege => $held) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe($held, "{$privilege} on {$table}");
        }
    }

    foreach (['revisions_revision_id_seq', 'release_log_release_id_seq'] as $sequence) {
        expect($app->scalar("select has_sequence_privilege(current_user, ?::regclass, 'USAGE')", [$sequence]))->toBeTrue($sequence);
    }
});

it('lets the app role read no rows, insert none and change none without an actor context, and run no DDL on the tables', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $superuser->table('changeset_principals')->insert(['changeset_id' => StorageTables::CHANGESET, 'position' => 1, 'actor_id' => REVISION_STORAGE_ACTOR]);
    $superuser->table('changeset_reason_texts')->insert(['changeset_id' => StorageTables::CHANGESET, 'classification' => 'confidential', 'text' => 'Wrong photo credit.', 'created_at' => StorageTables::CREATED_AT]);
    $superuser->table('revision_payloads')->insert(['revision_id' => 1, 'kind' => 'draft', 'format_version' => 1, 'content' => '{"value":"A"}']);
    $superuser->table('head_snapshots')->insert(['entry_id' => StorageTables::ENTRY, 'variant' => 'shared', 'schema_version' => 1, 'format_version' => 1, 'content' => '{"value":"A"}', 'updated_at' => StorageTables::CREATED_AT]);
    $superuser->table('release_log')->insert(['entry_id' => StorageTables::ENTRY, 'variant' => 'shared', 'action' => 'released', 'revision_id' => 2, 'effective_at' => StorageTables::CREATED_AT, 'changeset_id' => StorageTables::CHANGESET]);
    $app = DB::connection();

    foreach (REVISION_STORAGE_TABLES as $table) {
        expect($superuser->table($table)->count())->toBe(1 + ($table === 'revisions' ? 2 : 0), $table)
            ->and($app->table($table)->count())->toBe(0, $table);
    }

    $other = REVISION_STORAGE_OTHER_CHANGESET;

    // The app role holds INSERT, and row level security without an actor context refuses every new row.
    foreach ([
        'changeset_register' => ['changeset_id' => $other, 'retention_class' => 'standard'],
        'changesets' => changesetRow(['changeset_id' => StorageTables::CHANGESET]),
        'changeset_principals' => ['changeset_id' => StorageTables::CHANGESET, 'position' => 2, 'actor_id' => REVISION_STORAGE_ACTOR],
        'changeset_reason_texts' => ['changeset_id' => StorageTables::CHANGESET, 'classification' => 'confidential', 'text' => 'Again.', 'created_at' => StorageTables::CREATED_AT],
        'revisions' => StorageTables::revision(4),
        'revision_payloads' => ['revision_id' => 4, 'kind' => 'draft', 'format_version' => 1, 'content' => '{}'],
        'head_snapshots' => ['entry_id' => StorageTables::ENTRY, 'variant' => 'da', 'schema_version' => 1, 'format_version' => 1, 'content' => '{}', 'updated_at' => StorageTables::CREATED_AT],
        'release_log' => ['entry_id' => StorageTables::ENTRY, 'variant' => 'shared', 'action' => 'withdrawn', 'revision_id' => null, 'effective_at' => StorageTables::CREATED_AT, 'changeset_id' => StorageTables::CHANGESET],
    ] as $table => $row) {
        expect(StorageTables::sqlState(fn (): bool => $app->table($table)->insert($row)))->toBe('42501', $table);
    }

    foreach (['changeset_register', 'changesets', 'changeset_principals', 'changeset_reason_texts', 'revisions', 'revision_payloads', 'release_log'] as $table) {
        expect(StorageTables::sqlState(fn (): int => $app->table($table)->update($table === 'revision_payloads' ? ['format_version' => 2] : ['changeset_id' => $other])))->toBe('42501', 'update '.$table)
            ->and(StorageTables::sqlState(fn (): int => $app->table($table)->delete()))->toBe('42501', 'delete '.$table);
    }

    expect($app->table('head_snapshots')->where('entry_id', StorageTables::ENTRY)->update(['schema_version' => 2]))->toBe(0)
        ->and($superuser->table('head_snapshots')->value('schema_version'))->toBe(1);

    foreach ([
        'create table revision_probe (id bigint)',
        'alter table revisions add column probe text',
        'alter table changesets disable row level security',
        'create index revisions_probe on revisions (kind)',
        'create policy revision_probe on revisions for select using (true)',
        'create table revision_payloads_draft_p9 partition of revision_payloads_draft for values from (900000000) to (900000001)',
        'alter sequence revisions_revision_id_seq restart',
        'drop table release_log',
    ] as $ddl) {
        expect(StorageTables::sqlState(fn (): bool => $app->statement($ddl)))->toBe('42501', $ddl);
    }
});

it('keeps one register row per changeset, whatever its retention class, and a changeset\'s metadata to its forms', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $changeset = static fn (array $changes): Closure => static fn (): bool => $superuser->table('changesets')->insert(changesetRow(array_merge(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET], $changes)));
    $register = static fn (string $id, string $class): Closure => static fn (): bool => $superuser->table('changeset_register')->insert(['changeset_id' => $id, 'retention_class' => $class]);

    expect(StorageTables::violation($register(StorageTables::CHANGESET, 'evidence')))->toBe('23505 changeset_register_pkey')
        ->and(StorageTables::violation($register(REVISION_STORAGE_OTHER_CHANGESET, 'forever')))->toBe('23514 changeset_register_retention_class')
        ->and(StorageTables::violation($changeset([])))->toBe('23503 changesets_changeset_id_fkey');

    $register(REVISION_STORAGE_OTHER_CHANGESET, 'evidence')();

    foreach ([
        'changesets_command' => ['command' => 'revise'],
        'changesets_command_version' => ['command_version' => 0],
        'changesets_issuer_kind' => ['issuer_kind' => 'robot'],
        'changesets_surface' => ['surface' => 'graphql'],
        'changesets_reason_code' => ['reason_code' => 'Wrong credit'],
        'changesets_idempotency_key' => ['idempotency_key' => 'key one'],
        'changesets_correlation_id' => ['correlation_id' => str_repeat('a', 129)],
        'changesets_provenance_model' => ['provenance_model' => 'model'],
        'changesets_provenance_needs_model' => ['provenance_prompt' => 'prompt:1'],
        'changesets_provenance_parameters' => ['provenance_model' => 'model', 'provenance_model_version' => '1', 'provenance_parameters' => '[]'],
        'changesets_provenance_sources' => ['provenance_sources' => '{}'],
        'changesets_format_version' => ['format_version' => 0],
    ] as $constraint => $changes) {
        expect(StorageTables::violation($changeset($changes)))->toBe('23514 '.$constraint, (string) json_encode($changes));
    }

    expect(StorageTables::violation($changeset(['actor_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 changesets_actor_id_fkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('changesets')->insert(changesetRow())))->toBe('23505 changesets_p20260310_pkey');

    $changeset([
        'command' => 'variant.release',
        'issuer_kind' => 'agent',
        'surface' => 'mcp',
        'reason_code' => 'legal_request',
        'provenance_model' => 'model',
        'provenance_model_version' => '2026-01',
        'provenance_parameters' => '{"temperature":"0.2"}',
        'provenance_prompt' => 'prompt:1',
        'provenance_sources' => '["https://example.com/feed/1"]',
    ])();

    expect($superuser->table('changesets')->count())->toBe(2);
});

it('takes the commit position from the writing transaction\'s id', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $superuser->beginTransaction();
    $superuser->table('changeset_register')->insert(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET, 'retention_class' => 'standard']);
    $superuser->table('changesets')->insert(changesetRow(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET]));
    $own = $superuser->scalar('select xid = pg_current_xact_id() from changesets where changeset_id = ?', [REVISION_STORAGE_OTHER_CHANGESET]);
    $superuser->commit();

    expect($own)->toBeTrue()
        ->and($superuser->scalar('select count(distinct xid) from changesets'))->toBe(2)
        ->and(StorageTables::sqlState(static fn (): bool => $superuser->statement("insert into changesets (changeset_id, command, command_version, actor_id, issuer_kind, surface, idempotency_key, correlation_id, format_version, xid, created_at) values (?, 'entry.revise', 1, ?, 'human', 'rest', 'k', 'c', 1, null, now())", [REVISION_STORAGE_OTHER_CHANGESET, REVISION_STORAGE_ACTOR])))->toBe('23502');
});

it('keeps the on-behalf-of chain in order, with principals that exist, and the reason\'s text classified', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $principal = static fn (array $changes): Closure => static fn (): bool => $superuser->table('changeset_principals')->insert(array_merge(['changeset_id' => StorageTables::CHANGESET, 'position' => 1, 'actor_id' => REVISION_STORAGE_ACTOR], $changes));
    $reason = static fn (array $changes): Closure => static fn (): bool => $superuser->table('changeset_reason_texts')->insert(array_merge(['changeset_id' => StorageTables::CHANGESET, 'classification' => 'personal', 'text' => 'Requested by the person.', 'created_at' => StorageTables::CREATED_AT], $changes));

    $principal([])();

    expect(StorageTables::violation($principal([])))->toBe('23505 changeset_principals_p20260310_pkey')
        ->and(StorageTables::violation($principal(['position' => 0])))->toBe('23514 changeset_principals_position')
        ->and(StorageTables::violation($principal(['position' => 2, 'actor_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 changeset_principals_actor_id_fkey')
        ->and(StorageTables::violation($principal(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET, 'position' => 2])))->toBe('23503 changeset_principals_changeset_id_fkey')
        ->and(StorageTables::violation($reason(['classification' => 'secret'])))->toBe('23514 changeset_reason_texts_classification')
        ->and(StorageTables::violation($reason(['text' => '   '])))->toBe('23514 changeset_reason_texts_text')
        ->and(StorageTables::violation($reason(['text' => str_repeat('æ', 2001)])))->toBe('23514 changeset_reason_texts_text')
        ->and(StorageTables::violation($reason(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 changeset_reason_texts_changeset_id_fkey');

    $reason(['text' => str_repeat('æ', 2000)])();

    expect(StorageTables::violation($reason([])))->toBe('23505 changeset_reason_texts_pkey')
        ->and(StorageTables::texts($superuser, "select column_name::text as value from information_schema.columns where table_name = 'changesets' and column_name like '%reason%' order by 1"))->toBe(['reason_code']);
});

it('keeps one revision per entry, variant and number in the register, of a known kind and changeset', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $insert = static fn (array $changes): Closure => static fn (): bool => $superuser->table('revisions')->insert(array_merge(StorageTables::revision(4), $changes));

    expect(StorageTables::violation($insert(['rev_no' => 3])))->toBe('23505 revisions_entry_variant_rev_no_key')
        ->and(StorageTables::violation($insert(['revision_id' => 3])))->toBe('23505 revisions_pkey')
        ->and(StorageTables::violation($insert(['variant' => 'Shared'])))->toBe('23514 revisions_variant')
        ->and(StorageTables::violation($insert(['rev_no' => 0])))->toBe('23514 revisions_rev_no')
        ->and(StorageTables::violation($insert(['kind' => 'autosave'])))->toBe('23514 revisions_kind')
        ->and(StorageTables::violation($insert(['schema_version' => 0])))->toBe('23514 revisions_schema_version')
        ->and(StorageTables::violation($insert(['entry_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 revisions_entry_id_fkey')
        ->and(StorageTables::violation($insert(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 revisions_changeset_id_fkey');

    $insert(['variant' => 'en-GB', 'rev_no' => 3])();
    $superuser->statement("select setval('revisions_revision_id_seq', 4)");
    $next = $superuser->table('revisions')->insertGetId(array_merge(array_diff_key(StorageTables::revision(0, 'published'), ['revision_id' => true]), ['rev_no' => 5]), 'revision_id');

    expect($next)->toBe(5)
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('variant_heads')->where('entry_id', StorageTables::ENTRY)->update(['draft_revision_id' => 99]) > 0))->toBe('23503 variant_heads_draft_revision_id_fkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('variant_heads')->where('entry_id', StorageTables::ENTRY)->update(['published_revision_id' => 99]) > 0))->toBe('23503 variant_heads_published_revision_id_fkey')
        ->and(StorageTables::violation(static fn (): bool => $superuser->table('revisions')->where('revision_id', 1)->delete() > 0))->toBe('23503 variant_heads_draft_revision_id_fkey');
});

it('keeps each payload in the partition of its kind and revision_id, once per revision and kind', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $payload = static fn (array $changes): Closure => static fn (): bool => $superuser->table('revision_payloads')->insert(array_merge(['revision_id' => 1, 'kind' => 'draft', 'format_version' => 1, 'content' => '{"value":"A"}'], $changes));

    $payload([])();
    $payload(['revision_id' => 2, 'kind' => 'published'])();
    $payload(['revision_id' => 29_999_999, 'kind' => 'published'])();

    expect(StorageTables::texts($superuser, 'select tableoid::regclass::text || \' \' || revision_id::text as value from revision_payloads order by revision_id'))->toBe([
        'revision_payloads_draft_p0000000000000000000 1',
        'revision_payloads_published_p0000000000000000000 2',
        'revision_payloads_published_p0000000000020000000 29999999',
    ])->and(StorageTables::violation($payload([])))->toBe('23505 revision_payloads_draft_p0000000000000000000_pkey')
        ->and(StorageTables::violation($payload(['revision_id' => 0])))->toBe('23514 revision_payloads_revision_id')
        ->and(StorageTables::violation($payload(['revision_id' => 3, 'format_version' => 0])))->toBe('23514 revision_payloads_format_version')
        ->and(StorageTables::violation($payload(['revision_id' => 3, 'content' => '[]'])))->toBe('23514 revision_payloads_content');

    foreach ([['revision_id' => 30_000_000], ['revision_id' => 3, 'kind' => 'autosave']] as $changes) {
        expect(StorageTables::sqlState($payload($changes)))->toBe('23514', (string) json_encode($changes));
    }
});

it('keeps one snapshot per variant head, and the release log to its heads, revisions and changesets', function (): void {
    seedChangeset();
    $superuser = StorageTables::superuser();
    $snapshot = static fn (array $changes): Closure => static fn (): bool => $superuser->table('head_snapshots')->insert(array_merge(['entry_id' => StorageTables::ENTRY, 'variant' => 'shared', 'schema_version' => 1, 'format_version' => 1, 'content' => '{}', 'updated_at' => StorageTables::CREATED_AT], $changes));
    $release = static fn (array $changes): Closure => static fn (): bool => $superuser->table('release_log')->insert(array_merge(['entry_id' => StorageTables::ENTRY, 'variant' => 'shared', 'action' => 'released', 'revision_id' => 2, 'effective_at' => StorageTables::CREATED_AT, 'changeset_id' => StorageTables::CHANGESET], $changes));

    $snapshot([])();

    expect(StorageTables::violation($snapshot([])))->toBe('23505 head_snapshots_pkey')
        ->and(StorageTables::violation($snapshot(['variant' => 'da'])))->toBe('23503 head_snapshots_head_fkey')
        ->and(StorageTables::violation($snapshot(['variant' => 'da', 'schema_version' => 0])))->toBe('23514 head_snapshots_schema_version')
        ->and(StorageTables::violation($snapshot(['variant' => 'da', 'format_version' => 0])))->toBe('23514 head_snapshots_format_version')
        ->and(StorageTables::violation($snapshot(['variant' => 'da', 'content' => '"text"'])))->toBe('23514 head_snapshots_content');

    $release([])();
    $release(['action' => 'withdrawn', 'revision_id' => null, 'effective_at' => '2026-03-10 14:00:00+00'])();
    $release(['revision_id' => 3, 'effective_at' => '2026-03-10 16:00:00+00'])();

    // PRD 5.6: which revision was public at a given time is one lookup on the head's log.
    $publicAt = static fn (string $at): mixed => $superuser->scalar(
        'select revision_id from release_log where entry_id = ? and variant = ? and effective_at <= ?::timestamptz order by effective_at desc limit 1',
        [StorageTables::ENTRY, 'shared', $at],
    );

    expect($publicAt('2026-03-10 13:00:00+00'))->toBe(2)
        ->and($publicAt('2026-03-10 15:00:00+00'))->toBeNull()
        ->and($publicAt('2026-03-10 17:00:00+00'))->toBe(3)
        ->and(StorageTables::violation($release(['action' => 'published'])))->toBe('23514 release_log_action')
        ->and(StorageTables::violation($release(['revision_id' => null])))->toBe('23514 release_log_revision')
        ->and(StorageTables::violation($release(['action' => 'withdrawn'])))->toBe('23514 release_log_revision')
        ->and(StorageTables::violation($release(['revision_id' => 99])))->toBe('23503 release_log_revision_id_fkey')
        ->and(StorageTables::violation($release(['variant' => 'da'])))->toBe('23503 release_log_head_fkey')
        ->and(StorageTables::violation($release(['changeset_id' => REVISION_STORAGE_OTHER_CHANGESET])))->toBe('23503 release_log_changeset_id_fkey');
});

it('gets its runway from cms:partitions:maintain, after which the doctor\'s partitions.runway passes', function (): void {
    PartitionScratch::clockAt('2026-03-10T12:00:00Z');
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:partitions:maintain');
    $output = $artisan->output();

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('runway changesets until 2026-03-25T00:00:00Z')
        ->and($output)->toContain('runway changeset_principals until 2026-03-25T00:00:00Z')
        ->and($output)->toContain('runway revision_payloads_draft until id 30000000 (2 partitions ahead of id 0)')
        ->and($output)->toContain('runway revision_payloads_published until id 30000000 (2 partitions ahead of id 0)')
        ->and(StorageTables::texts(DB::connection('pgsql_owner'), "select inhrelid::regclass::text as value from pg_inherits where inhparent = 'revision_payloads_draft'::regclass order by 1"))->toBe([
            'revision_payloads_draft_p0000000000000000000',
            'revision_payloads_draft_p0000000000010000000',
            'revision_payloads_draft_p0000000000020000000',
        ])
        ->and(StorageTables::texts(DB::connection('pgsql_owner'), "select inhrelid::regclass::text as value from pg_inherits where inhparent = 'changesets'::regclass order by 1"))
        ->toContain(...array_map(static fn (int $day): string => sprintf('changesets_p202603%02d', $day), range(10, 24)));

    app()->instance(PhpSettingsProbe::class, new FakePhpSettingsProbe(allowUrlFopen: false));
    $artisan->call('cms:doctor', ['--json' => true]);
    $document = json_decode($artisan->output(), true, 512, JSON_THROW_ON_ERROR);
    $checks = is_array($document) && is_array($document['checks'] ?? null) ? $document['checks'] : [];
    $runway = array_values(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['id'] ?? null) === PartitionRunwayCheck::ID));

    expect($runway)->toHaveCount(1)
        ->and($runway[0]['status'] ?? null)->toBe('pass', (string) json_encode($runway));
});

<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * The access tables as the access migration leaves them (PRD 5.10, 12.2, 12.12, 4.1): roles with
 * their permissions and classification ceiling, grants of a role to an actor on a node with a locale
 * set, and the audit, partitioned per day with one row per changeset and no text; forced row level
 * security, the policies over the actor context, the owner's policy for the fixtures, and the app
 * role's grants. Rows are written as the superuser, which row level security does not hold.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * An audit row of the world's changeset by Alice, with the changes.
 *
 * @param  array<mixed>  $changes
 * @return array<mixed>
 */
function auditRow(array $changes = []): array
{
    return array_merge([
        'changeset_id' => AccessWorld::CHANGESET_ALICE,
        'actor_id' => AccessWorld::ALICE,
        'command' => 'entry.revise',
        'command_version' => 1,
        'issuer_kind' => 'human',
        'surface' => 'rest',
        'reason_code' => null,
        'legal_basis' => null,
        'aggregates' => '{entry:'.AccessWorld::ENTRY_NEWS.'}',
        'created_at' => AccessWorld::CREATED_AT,
    ], $changes);
}

/**
 * A write of the row as the superuser inside a transaction that is rolled back, so each refusal
 * is tried against the seeded world alone.
 *
 * @param  array<mixed>  $row
 * @return Closure(): void
 */
function superuserInsert(string $table, array $row): Closure
{
    return static function () use ($table, $row): void {
        $superuser = StorageTables::superuser();
        $superuser->beginTransaction();

        try {
            $superuser->table($table)->insert($row);
        } finally {
            $superuser->rollBack();
        }
    };
}

it('has the keys, foreign keys and indexes of roles, grants and the audit, and partitions the audit per day on changeset_id', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = '{roles,role_permissions,grants,audit}';

    expect(StorageTables::texts($owner, "select regexp_replace(indexdef, ' ON (ONLY )?[a-z0-9_.]+\\.', ' ON \\1') as value from pg_indexes where tablename = any (?::text[]) and schemaname = current_schema() order by tablename, indexname", [$tables]))->toBe([
        'CREATE INDEX audit_actor_id ON ONLY audit USING btree (actor_id)',
        'CREATE UNIQUE INDEX audit_pkey ON ONLY audit USING btree (changeset_id)',
        'CREATE INDEX grants_actor_id ON grants USING btree (actor_id)',
        'CREATE UNIQUE INDEX grants_actor_role_node_key ON grants USING btree (actor_id, role_id, node_id) WHERE (ended_changeset_id IS NULL)',
        'CREATE INDEX grants_ended_changeset_id ON grants USING btree (ended_changeset_id)',
        'CREATE INDEX grants_node_id ON grants USING btree (node_id)',
        'CREATE UNIQUE INDEX grants_pkey ON grants USING btree (id)',
        'CREATE INDEX grants_role_id ON grants USING btree (role_id)',
        'CREATE UNIQUE INDEX role_permissions_pkey ON role_permissions USING btree (role_id, command)',
        'CREATE UNIQUE INDEX roles_handle_key ON roles USING btree (handle)',
        'CREATE UNIQUE INDEX roles_pkey ON roles USING btree (id)',
    ])->and(StorageTables::texts($owner, "select conrelid::regclass::text || ' ' || pg_get_constraintdef(oid) as value from pg_constraint where conrelid = any (?::regclass[]) and contype = 'f' order by 1", [$tables]))->toBe([
        'audit FOREIGN KEY (actor_id) REFERENCES actors(id)',
        'audit FOREIGN KEY (changeset_id) REFERENCES changeset_register(changeset_id)',
        'grants FOREIGN KEY (actor_id) REFERENCES actors(id)',
        'grants FOREIGN KEY (ended_changeset_id) REFERENCES changeset_register(changeset_id)',
        'grants FOREIGN KEY (node_id) REFERENCES nodes(id)',
        'grants FOREIGN KEY (role_id) REFERENCES roles(id)',
        'role_permissions FOREIGN KEY (role_id) REFERENCES roles(id)',
    ])->and(StorageTables::texts($owner, "select pg_get_partkeydef('audit'::regclass) as value"))->toBe(['RANGE (changeset_id)'])
        ->and(StorageTables::texts($owner, "select column_name::text || ' ' || data_type::text as value from information_schema.columns where table_name = 'audit' and table_schema = current_schema() order by ordinal_position"))->toBe([
            'changeset_id uuid',
            'actor_id uuid',
            'command text',
            'command_version integer',
            'issuer_kind text',
            'surface text',
            'reason_code text',
            'legal_basis text',
            'aggregates ARRAY',
            'xid xid8',
            'created_at timestamp with time zone',
        ]);
});

it('forces row level security on the access tables, gives the app role SELECT on roles and grants and SELECT and INSERT on the audit, and the owner the writes', function (): void {
    $owner = DB::connection('pgsql_owner');
    $app = DB::connection();
    $ownerRole = StorageTables::texts($owner, 'select current_user::text as value')[0];
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect(StorageTables::texts($owner, "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = any ('{audit,grants,role_permissions,roles}'::regclass[]) order by 1"))
        ->toBe(['audit true true', 'grants true true', 'role_permissions true true', 'roles true true'])
        ->and(StorageTables::texts($owner, "select tablename::text || ' ' || policyname::text || ' ' || cmd::text || ' ' || array_to_string(roles, ',') || ' ' || coalesce(qual, '-') || ' ' || coalesce(with_check, '-') as value from pg_policies where tablename = any ('{audit,grants,role_permissions,roles}'::text[]) order by 1"))->toBe([
            'audit audit_write INSERT public - (actor_id = cms_access_actor())',
            'grants grants_owner_write ALL '.$ownerRole.' true true',
            'grants grants_read SELECT public (actor_id = cms_access_actor()) -',
            'role_permissions role_permissions_owner_write ALL '.$ownerRole.' true true',
            'role_permissions role_permissions_read SELECT public (cms_access_actor() IS NOT NULL) -',
            'roles roles_owner_write ALL '.$ownerRole.' true true',
            'roles roles_read SELECT public (cms_access_actor() IS NOT NULL) -',
        ])
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);

    foreach (['roles' => ['SELECT'], 'role_permissions' => ['SELECT'], 'grants' => ['SELECT'], 'audit' => ['SELECT', 'INSERT']] as $table => $held) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe(in_array($privilege, $held, true), "{$privilege} on {$table}");
        }
    }
});

it('keeps a role\'s handle, ceiling and permissions, and a grant\'s effect and locale set, to their forms', function (): void {
    AccessWorld::seed();
    $role = static fn (array $changes): Closure => superuserInsert('roles', array_merge(['id' => '0192a0c0-0000-7000-8000-0000000000f9', 'handle' => 'other', 'classification_ceiling' => 'public', 'version' => 1, 'created_at' => AccessWorld::CREATED_AT], $changes));
    $roleId = StorageTables::texts(StorageTables::superuser(), "select id::text as value from roles where handle = 'desk'")[0];
    $permission = static fn (string $command): Closure => superuserInsert('role_permissions', ['role_id' => $roleId, 'command' => $command, 'created_at' => AccessWorld::CREATED_AT]);
    $grant = static fn (array $changes): Closure => superuserInsert('grants', array_merge([
        'id' => '0192a0c0-0000-7000-8000-0000000000fa',
        'actor_id' => AccessWorld::BOB,
        'role_id' => $roleId,
        'node_id' => AccessWorld::CULTURE,
        'effect' => 'allow',
        'locales' => null,
        'version' => 1,
        'created_at' => AccessWorld::CREATED_AT,
    ], $changes));

    $role(['handle' => 'reviewer'])();
    $permission('placement.set_window')();
    $grant(['locales' => '{da,en-GB,zh-Hant-TW}'])();
    $grant(['effect' => 'deny'])();

    expect(StorageTables::violation($role(['handle' => 'desk'])))->toBe('23505 roles_handle_key')
        ->and(StorageTables::violation($role(['handle' => 'Desk'])))->toBe('23514 roles_handle')
        ->and(StorageTables::violation($role(['classification_ceiling' => 'secret'])))->toBe('23514 roles_classification_ceiling')
        ->and(StorageTables::violation($role(['version' => 0])))->toBe('23514 roles_version')
        ->and(StorageTables::violation($permission('entry')))->toBe('23514 role_permissions_command')
        ->and(StorageTables::violation($permission('Entry.create')))->toBe('23514 role_permissions_command')
        ->and(StorageTables::violation($permission('entry.create')))->toBe('23505 role_permissions_pkey')
        ->and(StorageTables::violation($grant(['effect' => 'maybe'])))->toBe('23514 grants_effect')
        ->and(StorageTables::violation($grant(['locales' => '{}'])))->toBe('23514 grants_locales')
        ->and(StorageTables::violation($grant(['locales' => '{da,NULL}'])))->toBe('23514 grants_locales')
        ->and(StorageTables::violation($grant(['locales' => '{da,"en gb"}'])))->toBe('23514 grants_locales')
        ->and(StorageTables::violation($grant(['version' => 0])))->toBe('23514 grants_version')
        ->and(StorageTables::violation($grant(['node_id' => AccessWorld::SPORT])))->toBe('23505 grants_actor_role_node_key')
        ->and(StorageTables::violation($grant(['node_id' => '0192a0c0-0000-7000-8000-0000000000ff'])))->toBe('23503 grants_node_id_fkey')
        ->and(StorageTables::violation($grant(['actor_id' => '0192a0c0-0000-7000-8000-0000000000ff'])))->toBe('23503 grants_actor_id_fkey')
        ->and(StorageTables::violation($grant(['role_id' => '0192a0c0-0000-7000-8000-0000000000ff'])))->toBe('23503 grants_role_id_fkey');
});

it('keeps one audit row per changeset, of ids, codes and enums, with the commit position', function (): void {
    AccessWorld::seed();
    $superuser = StorageTables::superuser();
    $audit = static fn (array $changes): Closure => superuserInsert('audit', auditRow($changes));
    $superuser->table('changeset_register')->insert(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4', 'retention_class' => 'evidence']);
    $fresh = ['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4'];

    $audit([...$fresh, 'reason_code' => 'legal_takedown', 'legal_basis' => 'legal_obligation', 'aggregates' => '{entry:'.AccessWorld::ENTRY_NEWS.',placement:'.AccessWorld::PLACEMENT_DRAFT.'}'])();

    expect(StorageTables::violation($audit([])))->toBe('23505 audit_p20260310_pkey')
        ->and(StorageTables::violation($audit([...$fresh, 'command' => 'Entry revise'])))->toBe('23514 audit_command')
        ->and(StorageTables::violation($audit([...$fresh, 'command_version' => 0])))->toBe('23514 audit_command_version')
        ->and(StorageTables::violation($audit([...$fresh, 'issuer_kind' => 'robot'])))->toBe('23514 audit_issuer_kind')
        ->and(StorageTables::violation($audit([...$fresh, 'surface' => 'email'])))->toBe('23514 audit_surface')
        ->and(StorageTables::violation($audit([...$fresh, 'reason_code' => 'Asked by the source'])))->toBe('23514 audit_reason_code')
        ->and(StorageTables::violation($audit([...$fresh, 'legal_basis' => 'because'])))->toBe('23514 audit_legal_basis')
        ->and(StorageTables::violation($audit([...$fresh, 'aggregates' => '{}'])))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit([...$fresh, 'aggregates' => '{"entry:the headline"}'])))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit([...$fresh, 'aggregates' => '{entry:'.AccessWorld::ENTRY_NEWS.',NULL}'])))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit([...$fresh, 'aggregates' => '{"entry:'.AccessWorld::ENTRY_NEWS.' entry:'.AccessWorld::ENTRY_SPORT.'"}'])))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit([...$fresh, 'actor_id' => '0192a0c0-0000-7000-8000-0000000000ff'])))->toBe('23503 audit_actor_id_fkey')
        ->and(StorageTables::violation($audit(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f5'])))->toBe('23503 audit_changeset_id_fkey');

    $superuser->beginTransaction();
    $superuser->table('changeset_register')->insert(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f6', 'retention_class' => 'standard']);
    $superuser->table('audit')->insert(auditRow(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f6']));
    $position = $superuser->scalar('select (xid = pg_current_xact_id())::text from audit where changeset_id = ?', ['019cd79e-4600-7000-8000-0000000000f6']);
    $superuser->rollBack();

    expect($position)->toBe('true');
});

it('lets the actor of the context alone write its audit row, and nobody read one', function (): void {
    AccessWorld::seed();
    $app = DB::connection();
    $superuser = StorageTables::superuser();
    $superuser->table('changeset_register')->insert(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4', 'retention_class' => 'standard']);
    $row = auditRow(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4']);

    $app->beginTransaction();
    new ActorContext(app('db'))->set(new AccessCompiler()->compile(AccessWorld::bob(), []));
    $asBob = StorageTables::sqlState(fn (): bool => $app->table('audit')->insert($row));
    $app->rollBack();

    $app->beginTransaction();
    new ActorContext(app('db'))->set(new AccessCompiler()->compile(AccessWorld::alice(), []));
    $inserted = $app->table('audit')->insert($row);
    $read = $app->table('audit')->count();
    $app->commit();

    expect($asBob)->toBe('42501')
        ->and($inserted)->toBeTrue()
        ->and($read)->toBe(0)
        ->and($superuser->table('audit')->where('changeset_id', '019cd79e-4600-7000-8000-0000000000f4')->value('actor_id'))->toBe(AccessWorld::ALICE);
});

it('gets the audit\'s runway from cms:partitions:maintain, after which the doctor\'s partitions.runway passes', function (): void {
    PartitionScratch::clockAt('2026-03-10T12:00:00Z');
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:partitions:maintain');
    $output = $artisan->output();

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('runway audit until 2026-03-25T00:00:00Z')
        ->and(StorageTables::texts(DB::connection('pgsql_owner'), "select inhrelid::regclass::text as value from pg_inherits where inhparent = 'audit'::regclass order by 1"))
        ->toContain(...array_map(static fn (int $day): string => sprintf('audit_p202603%02d', $day), range(10, 24)))
        ->and(StorageTables::texts(DB::connection('pgsql_owner'), "select relname::text || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname = 'audit_p20260310'"))
        ->toBe(['audit_p20260310 true true'])
        ->and(DB::connection()->scalar("select has_table_privilege(current_user, 'audit_p20260310', 'INSERT')"))->toBeTrue()
        ->and(DB::connection()->scalar("select has_table_privilege(current_user, 'audit_p20260310', 'UPDATE')"))->toBeFalse();

    app()->instance(PhpSettingsProbe::class, new FakePhpSettingsProbe(allowUrlFopen: false));
    $artisan->call('cms:doctor', ['--json' => true]);
    $document = json_decode($artisan->output(), true, 512, JSON_THROW_ON_ERROR);
    $checks = is_array($document) && is_array($document['checks'] ?? null) ? $document['checks'] : [];
    $runway = array_values(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['id'] ?? null) === PartitionRunwayCheck::ID));

    expect($runway)->toHaveCount(1)
        ->and($runway[0]['status'] ?? null)->toBe('pass', (string) json_encode($runway));
});

it('takes the key of a variant among the audit\'s aggregates, and nothing but a variant key after it', function (): void {
    AccessWorld::seed();
    $audit = static fn (string $aggregates): Closure => superuserInsert('audit', auditRow(['aggregates' => $aggregates]));
    $variant = 'variant:'.AccessWorld::ENTRY_NEWS;

    expect(StorageTables::violation($audit('{'.$variant.':Shared}')))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit('{"'.$variant.':the headline"}')))->toBe('23514 audit_aggregates')
        ->and(StorageTables::violation($audit('{'.$variant.':da:x}')))->toBe('23514 audit_aggregates');

    $superuser = StorageTables::superuser();
    $superuser->table('changeset_register')->insert(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4', 'retention_class' => 'standard']);
    $keys = '{entry:'.AccessWorld::ENTRY_NEWS.','.$variant.':shared,'.$variant.':en-GB}';
    $superuser->table('audit')->insert(auditRow(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f4', 'aggregates' => $keys]));

    expect($superuser->table('audit')->where('changeset_id', '019cd79e-4600-7000-8000-0000000000f4')->value('aggregates'))->toBe($keys);
});

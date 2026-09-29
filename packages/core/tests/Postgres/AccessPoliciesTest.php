<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Access\Adapter\PostgresAccessResolver;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Infrastructure\TypeTableAccess;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

/*
 * Access end to end on Postgres (PRD 5.10, 12.2): the testkit's fixtures write roles and grants as
 * the owner role, the resolver reads an actor's grants as the app role and compiles them into
 * access regions and classification access, and the policies decide what the app role reads and
 * writes as each context. Alice's grants have an allow, a deny below it and a more specific allow
 * below the deny; Bob's are one allow, and he owns an entry; the anonymous context reads only what
 * is released with a live placement.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * What the app role reads of each table as the context of the principal, by the table's key.
 *
 * @return array<string, list<string>>
 */
function readsAs(?Principal $principal): array
{
    $app = DB::connection();
    $app->beginTransaction();

    try {
        if ($principal instanceof Principal) {
            new PostgresAccessResolver(app('db'), new AccessCompiler)->resolve($principal);
        }

        return [
            'nodes' => accessKeys($app, 'nodes', 'id'),
            'entries' => accessKeys($app, 'entries', 'id'),
            'variant_heads' => accessKeys($app, 'variant_heads', 'entry_id'),
            'revisions' => accessKeys($app, 'revisions', 'revision_id::text'),
            'revision_payloads' => accessKeys($app, 'revision_payloads', 'revision_id::text'),
            'head_snapshots' => accessKeys($app, 'head_snapshots', 'entry_id'),
            'placements' => accessKeys($app, 'placements', 'id'),
            'placement_generations' => accessKeys($app, 'placement_generations', "placement_id || ' ' || stage"),
            'placement_locales' => accessKeys($app, 'placement_locales', "placement_id || ' ' || stage"),
            'changeset_reason_texts' => accessKeys($app, 'changeset_reason_texts', 'changeset_id'),
            'changesets' => accessKeys($app, 'changesets', 'changeset_id'),
            'changeset_principals' => accessKeys($app, 'changeset_principals', 'changeset_id'),
            'changeset_register' => accessKeys($app, 'changeset_register', 'changeset_id'),
            'release_log' => accessKeys($app, 'release_log', 'entry_id'),
            'roles' => accessKeys($app, 'roles', 'handle'),
            'role_permissions' => accessKeys($app, 'role_permissions', 'command'),
            'grants' => accessKeys($app, 'grants', 'node_id'),
            'audit' => accessKeys($app, 'audit', 'changeset_id'),
            'actors' => accessKeys($app, 'actors', 'id'),
            'sites' => accessKeys($app, 'sites', 'id'),
            'mount_overrides' => accessKeys($app, 'mount_overrides', 'entry_id'),
        ];
    } finally {
        $app->rollBack();
    }
}

/**
 * @return list<string> sorted
 */
function accessKeys(Connection $app, string $table, string $key): array
{
    return StorageTables::texts($app, sprintf('select distinct (%s)::text as value from %s order by 1', $key, $table));
}

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function sortedKeys(array $keys): array
{
    sort($keys);

    return $keys;
}

it('compiles an allow, a deny below it and a more specific allow below the deny into regions, with the highest ceiling capped by the credential', function (): void {
    AccessWorld::seed();
    $app = DB::connection();
    $resolver = new PostgresAccessResolver(app('db'), new AccessCompiler);

    $app->beginTransaction();
    $alice = $resolver->resolve(AccessWorld::alice());
    $bob = $resolver->resolve(AccessWorld::bob());
    $anonymous = $resolver->resolve(new AnonymousPrincipal);
    $app->rollBack();

    $news = new NodePath(AccessWorld::path(AccessWorld::ROOT, AccessWorld::NEWS));
    $sport = new NodePath(AccessWorld::path(AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT));
    $football = new NodePath(AccessWorld::path(AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL));
    $culture = new NodePath(AccessWorld::path(AccessWorld::ROOT, AccessWorld::CULTURE));

    expect($alice->regions)->toEqual(sortedRegions([new AccessRegion($news, [$sport]), new AccessRegion($football), new AccessRegion($culture)]))
        ->and($alice->classificationAccess)->toBe(ClassificationAccess::Confidential)
        ->and($alice->reaches($football))->toBeTrue()
        ->and($alice->reaches($sport))->toBeFalse()
        ->and($bob->regions)->toEqual([new AccessRegion($sport)])
        ->and($bob->classificationAccess)->toBe(ClassificationAccess::Internal)
        ->and($anonymous)->toEqual(AccessContext::anonymous());
});

/**
 * @param  list<AccessRegion>  $regions
 * @return list<AccessRegion>
 */
function sortedRegions(array $regions): array
{
    usort($regions, static fn (AccessRegion $a, AccessRegion $b): int => $a->path->value <=> $b->path->value);

    return $regions;
}

it('lets each actor read what its regions reach, its own rows and what is public, and the anonymous context only what is released with a live placement', function (): void {
    AccessWorld::seed();
    $nothing = array_map(static fn (): array => [], readsAs(null));
    $public = [
        'entries' => [AccessWorld::ENTRY_PUBLIC],
        'variant_heads' => [AccessWorld::ENTRY_PUBLIC],
        'revisions' => ['6'],
        'revision_payloads' => ['6'],
        'head_snapshots' => [AccessWorld::ENTRY_PUBLIC],
        'placements' => [AccessWorld::PLACEMENT_PUBLIC],
        'placement_generations' => [AccessWorld::PLACEMENT_PUBLIC.' released'],
        'placement_locales' => [AccessWorld::PLACEMENT_PUBLIC.' released'],
    ];
    $roles = ['roles' => ['desk', 'legal'], 'role_permissions' => ['entry.create', 'entry.revise']];

    expect(readsAs(null))->toBe($nothing)
        ->and(array_keys($nothing))->toContain('nodes', 'entries', 'audit', 'actors', 'sites', 'mount_overrides')
        ->and(readsAs(AccessWorld::alice()))->toBe(array_merge($nothing, $roles, [
            'nodes' => sortedKeys([AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL, AccessWorld::CULTURE]),
            'entries' => sortedKeys([AccessWorld::ENTRY_NEWS, AccessWorld::ENTRY_FOOTBALL, AccessWorld::ENTRY_CULTURE, AccessWorld::ENTRY_PUBLIC]),
            'variant_heads' => sortedKeys([AccessWorld::ENTRY_NEWS, AccessWorld::ENTRY_FOOTBALL, AccessWorld::ENTRY_CULTURE, AccessWorld::ENTRY_PUBLIC]),
            'revisions' => ['1', '3', '4', '6'],
            'revision_payloads' => ['1', '3', '4', '6'],
            'head_snapshots' => sortedKeys([AccessWorld::ENTRY_NEWS, AccessWorld::ENTRY_PUBLIC]),
            'placements' => sortedKeys([AccessWorld::PLACEMENT_PUBLIC, AccessWorld::PLACEMENT_DRAFT]),
            'placement_generations' => sortedKeys([AccessWorld::PLACEMENT_PUBLIC.' released', AccessWorld::PLACEMENT_DRAFT.' draft']),
            'placement_locales' => sortedKeys([AccessWorld::PLACEMENT_PUBLIC.' released', AccessWorld::PLACEMENT_DRAFT.' draft']),
            'changeset_reason_texts' => [AccessWorld::CHANGESET_ALICE],
            'changesets' => [AccessWorld::CHANGESET_ALICE, AccessWorld::CHANGESET_PERSONAL],
            'grants' => sortedKeys([AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL, AccessWorld::CULTURE]),
        ]))
        ->and(readsAs(AccessWorld::bob()))->toBe(array_merge($nothing, $roles, [
            'nodes' => sortedKeys([AccessWorld::SPORT, AccessWorld::FOOTBALL]),
            'entries' => sortedKeys([AccessWorld::ENTRY_SPORT, AccessWorld::ENTRY_FOOTBALL, AccessWorld::ENTRY_PUBLIC, AccessWorld::ENTRY_OWNED]),
            'variant_heads' => sortedKeys([AccessWorld::ENTRY_SPORT, AccessWorld::ENTRY_FOOTBALL, AccessWorld::ENTRY_PUBLIC, AccessWorld::ENTRY_OWNED]),
            'revisions' => ['2', '3', '6', '7'],
            'revision_payloads' => ['2', '3', '6', '7'],
            'head_snapshots' => [AccessWorld::ENTRY_PUBLIC],
            'placements' => [AccessWorld::PLACEMENT_PUBLIC],
            'placement_generations' => [AccessWorld::PLACEMENT_PUBLIC.' released'],
            'placement_locales' => [AccessWorld::PLACEMENT_PUBLIC.' released'],
            'changesets' => [AccessWorld::CHANGESET_BOB],
            'changeset_principals' => [AccessWorld::CHANGESET_BOB],
            'grants' => [AccessWorld::SPORT],
        ]))
        ->and(readsAs(new AnonymousPrincipal))->toBe(array_merge($nothing, $public));
});

/**
 * The SQLSTATE of the write as the principal's context, or 'ok' when it succeeds, in a
 * transaction that is rolled back.
 *
 * @param  callable(Connection): mixed  $write
 */
function writeAs(?Principal $principal, callable $write): string
{
    $app = DB::connection();
    $app->beginTransaction();

    try {
        if ($principal instanceof Principal) {
            new PostgresAccessResolver(app('db'), new AccessCompiler)->resolve($principal);
        }

        return StorageTables::sqlState(static function () use ($write, $app): void {
            $result = $write($app);

            throw new UnexpectedValueException(is_int($result) ? 'rows '.$result : 'ok');
        });
    } catch (UnexpectedValueException $done) {
        return $done->getMessage();
    } finally {
        $app->rollBack();
    }
}

it('holds an actor\'s writes to its regions and the anonymous context to none', function (): void {
    AccessWorld::seed();
    $entry = static fn (string $home): Closure => static fn (Connection $app): bool => $app->table('entries')->insert([
        'id' => '0192a0c0-0000-7000-8000-0000000000d9',
        'type_id' => AccessWorld::TYPE,
        'home_node_id' => $home,
        'owner_actor_id' => null,
        'lifecycle' => 'active',
        'version' => 1,
        'created_at' => AccessWorld::CREATED_AT,
    ]);
    $placement = static fn (string $node): Closure => static function (Connection $app) use ($node): bool {
        $app->table('placements')->insert(['id' => '0192a0c0-0000-7000-8000-0000000000e9', 'entry_id' => AccessWorld::ENTRY_PUBLIC, 'version' => 1, 'created_at' => AccessWorld::CREATED_AT]);

        return $app->table('placement_generations')->insert(['placement_id' => '0192a0c0-0000-7000-8000-0000000000e9', 'stage' => 'draft', 'node_id' => $node, 'created_at' => AccessWorld::CREATED_AT]);
    };
    $changeset = static fn (string $actor): Closure => static function (Connection $app) use ($actor): bool {
        $app->table('changeset_register')->insert(['changeset_id' => '019cd79e-4600-7000-8000-0000000000f9', 'retention_class' => 'standard']);

        return $app->table('changesets')->insert([
            'changeset_id' => '019cd79e-4600-7000-8000-0000000000f9',
            'command' => 'entry.create',
            'command_version' => 1,
            'actor_id' => $actor,
            'issuer_kind' => 'human',
            'surface' => 'rest',
            'idempotency_key' => 'key-new',
            'correlation_id' => 'trace-new',
            'format_version' => 1,
            'created_at' => AccessWorld::CREATED_AT,
        ]);
    };

    expect(writeAs(AccessWorld::alice(), $entry(AccessWorld::NEWS)))->toBe('ok')
        ->and(writeAs(AccessWorld::alice(), $entry(AccessWorld::FOOTBALL)))->toBe('ok')
        ->and(writeAs(AccessWorld::alice(), $entry(AccessWorld::SPORT)))->toBe('42501')
        ->and(writeAs(AccessWorld::alice(), $entry(AccessWorld::ROOT)))->toBe('42501')
        ->and(writeAs(AccessWorld::bob(), $entry(AccessWorld::SPORT)))->toBe('ok')
        ->and(writeAs(new AnonymousPrincipal, $entry(AccessWorld::NEWS)))->toBe('42501')
        ->and(writeAs(null, $entry(AccessWorld::NEWS)))->toBe('42501')
        ->and(writeAs(AccessWorld::alice(), static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_NEWS)->update(['lifecycle' => 'archived'])))->toBe('rows 1')
        ->and(writeAs(AccessWorld::alice(), static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_NEWS)->update(['home_node_id' => AccessWorld::SPORT])))->toBe('42501')
        ->and(writeAs(AccessWorld::alice(), static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_PUBLIC)->update(['lifecycle' => 'archived'])))->toBe('rows 0')
        ->and(writeAs(AccessWorld::bob(), static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_NEWS)->update(['lifecycle' => 'archived'])))->toBe('rows 0')
        ->and(writeAs(AccessWorld::bob(), static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_OWNED)->update(['lifecycle' => 'archived'])))->toBe('rows 1')
        ->and(writeAs(new AnonymousPrincipal, static fn (Connection $app): int => $app->table('entries')->where('id', AccessWorld::ENTRY_PUBLIC)->update(['lifecycle' => 'archived'])))->toBe('rows 0')
        ->and(writeAs(new AnonymousPrincipal, static fn (Connection $app): int => $app->table('variant_heads')->where('entry_id', AccessWorld::ENTRY_PUBLIC)->update(['version' => 2])))->toBe('rows 0')
        ->and(writeAs(AccessWorld::alice(), static fn (Connection $app): int => $app->table('revisions')->where('revision_id', 6)->update(['schema_version' => 2])))->toBe('42501')
        ->and(writeAs(AccessWorld::alice(), $placement(AccessWorld::NEWS)))->toBe('ok')
        ->and(writeAs(AccessWorld::alice(), $placement(AccessWorld::SPORT)))->toBe('42501')
        ->and(writeAs(AccessWorld::bob(), $placement(AccessWorld::SPORT)))->toBe('ok')
        ->and(writeAs(new AnonymousPrincipal, $placement(AccessWorld::NEWS)))->toBe('42501')
        ->and(writeAs(AccessWorld::alice(), $changeset(AccessWorld::ALICE)))->toBe('ok')
        ->and(writeAs(AccessWorld::alice(), $changeset(AccessWorld::BOB)))->toBe('42501')
        ->and(writeAs(new AnonymousPrincipal, $changeset(AccessWorld::ALICE)))->toBe('42501')
        ->and(writeAs(AccessWorld::bob(), static fn (Connection $app): bool => $app->table('changeset_reason_texts')->insert(['changeset_id' => AccessWorld::CHANGESET_ALICE, 'classification' => 'public', 'text' => 'Not mine.', 'created_at' => AccessWorld::CREATED_AT])))->toBe('42501');
});

it('protects a generated type table through its home node and owner, and shows its released rows with a live placement to every context', function (): void {
    AccessWorld::seed();
    $owner = DB::connection('pgsql_owner');
    $owner->statement('create table access_probe_items (cms_entry_id uuid not null, cms_stage text not null, cms_home_node uuid not null, cms_owner_actor uuid, value integer not null)');
    new TypeTableAccess($owner)->protect('access_probe_items');

    try {
        foreach ([
            [AccessWorld::ENTRY_NEWS, 'draft', AccessWorld::NEWS, null, 1],
            [AccessWorld::ENTRY_SPORT, 'draft', AccessWorld::SPORT, null, 2],
            [AccessWorld::ENTRY_CULTURE, 'draft', AccessWorld::CULTURE, null, 3],
            [AccessWorld::ENTRY_PUBLIC, 'released', AccessWorld::ROOT, null, 4],
            [AccessWorld::ENTRY_PUBLIC, 'draft', AccessWorld::ROOT, null, 5],
            [AccessWorld::ENTRY_OWNED, 'draft', AccessWorld::ROOT, AccessWorld::BOB, 6],
        ] as [$entry, $stage, $home, $actor, $value]) {
            StorageTables::superuser()->table('access_probe_items')->insert(['cms_entry_id' => $entry, 'cms_stage' => $stage, 'cms_home_node' => $home, 'cms_owner_actor' => $actor, 'value' => $value]);
        }

        $values = static function (?Principal $principal): array {
            $app = DB::connection();
            $app->beginTransaction();

            try {
                if ($principal instanceof Principal) {
                    new PostgresAccessResolver(app('db'), new AccessCompiler)->resolve($principal);
                }

                return StorageTables::texts($app, 'select value::text as value from access_probe_items order by value');
            } finally {
                $app->rollBack();
            }
        };

        expect(StorageTables::texts($owner, "select policyname::text || ' ' || cmd::text || ' ' || coalesce(qual, '-') || ' ' || coalesce(with_check, '-') as value from pg_policies where tablename = 'access_probe_items' order by 1"))->toBe([
            'access_probe_items_actor ALL cms_access_home(cms_home_node, cms_owner_actor) cms_access_home(cms_home_node, cms_owner_actor)',
            'access_probe_items_released SELECT cms_access_released(cms_entry_id, cms_stage) -',
        ])
            ->and(StorageTables::texts($owner, "select relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname = 'access_probe_items'"))->toBe(['true true'])
            ->and($values(null))->toBe([])
            ->and($values(new AnonymousPrincipal))->toBe(['4'])
            ->and($values(AccessWorld::alice()))->toBe(['1', '3', '4'])
            ->and($values(AccessWorld::bob()))->toBe(['2', '4', '6'])
            ->and(fn () => new TypeTableAccess($owner)->protect('access probe'))->toThrow(InvalidArgumentException::class, 'at most 54 characters, got "access probe"')
            ->and(fn () => new TypeTableAccess($owner)->protect(str_repeat('a', 55)))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new TypeTableAccess($owner)->protect('Items'))->toThrow(InvalidArgumentException::class);
    } finally {
        $owner->statement('drop table access_probe_items');
    }
});

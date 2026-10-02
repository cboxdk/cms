<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\Infrastructure\OwnerTruncation;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/*
 * PRD 5.16, "Lokale konti": the credential store runs on its own connection, in its own schema and
 * with its own role, and the app role cannot read credentials. The isolation is by privilege: the
 * app role has nothing on the schema cms_identity, the identity role reaches nothing else.
 */

const ACCOUNT_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaGhhc2g';

function identityConnection(): Connection
{
    return DB::connection('pgsql_identity');
}

/**
 * Active staff actors, written by the testkit's seeder as the owner role.
 *
 * @return non-empty-list<string>
 */
function accountActors(int $count = 1): array
{
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $seeder = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock));
    $actors = [$seeder->addActor(ActorClass::Staff, ActorState::Active)->id->toString()];

    for ($n = 1; $n < $count; $n++) {
        $actors[] = $seeder->addActor(ActorClass::Staff, ActorState::Active)->id->toString();
    }

    return $actors;
}

/**
 * Asserts that the statement fails with insufficient_privilege (SQLSTATE 42501).
 */
function expectPrivilegeRefused(Connection $connection, string $sql): void
{
    try {
        $connection->select($sql);
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501');

        return;
    }

    Assert::fail("Expected [{$sql}] to fail with SQLSTATE 42501, but it succeeded.");
}

it('refuses the app role every read and write of the credential store with SQLSTATE 42501', function (string $sql): void {
    expectPrivilegeRefused(DB::connection(), $sql);
})->with([
    'a read of the local accounts' => ['select * from cms_identity.local_accounts'],
    'a read of the reset tokens' => ['select * from cms_identity.password_reset_tokens'],
    'a write of the local accounts' => ["insert into cms_identity.local_accounts (actor_id) values ('0199c1f0-0000-7000-8000-000000000000') returning 1"],
]);

it('lets the identity connection insert and read a local account and its reset token', function (): void {
    [$actor] = accountActors();

    identityConnection()->table(CredentialStore::table('local_accounts'))->insert([
        'actor_id' => $actor,
        'login' => 'ada@example.test',
        'password_hash' => ACCOUNT_HASH,
        'password_changed_at' => '2026-03-10 12:00:00+00',
        'version' => 1,
        'created_at' => '2026-03-10 12:00:00+00',
    ]);
    identityConnection()->table(CredentialStore::table('password_reset_tokens'))->insert([
        'token_hash' => hash('sha256', 'token'),
        'actor_id' => $actor,
        'expires_at' => '2026-03-10 13:00:00+00',
        'created_at' => '2026-03-10 12:00:00+00',
    ]);

    expect(identityConnection()->table(CredentialStore::table('local_accounts'))->where('actor_id', $actor)->value('login'))->toBe('ada@example.test')
        ->and(identityConnection()->table(CredentialStore::table('password_reset_tokens'))->where('actor_id', $actor)->count())->toBe(1)
        ->and(identityConnection()->scalar('select current_user'))->toBe('cms_identity')
        ->and(identityConnection()->scalar('select current_schema()'))->toBe(CredentialStore::SCHEMA);
});

it('keeps the identity role out of the kernel\'s tables', function (): void {
    expectPrivilegeRefused(identityConnection(), 'select * from cms.actors');
});

it('refuses a credential whose form is wrong, whatever writes it', function (array $account, string $constraint): void {
    [$actor] = accountActors();

    try {
        identityConnection()->table(CredentialStore::table('local_accounts'))->insert([
            'actor_id' => $actor,
            'login' => 'ada@example.test',
            'password_hash' => ACCOUNT_HASH,
            'password_changed_at' => '2026-03-10 12:00:00+00',
            'version' => 1,
            'created_at' => '2026-03-10 12:00:00+00',
            ...$account,
        ]);
        Assert::fail("Expected the CHECK {$constraint} to refuse the row.");
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514')
            ->and($exception->getMessage())->toContain($constraint);
    }
})->with([
    'a login with capitals' => [['login' => 'Ada@example.test'], 'local_accounts_login'],
    'a login with a space' => [['login' => 'ada lovelace'], 'local_accounts_login'],
    'an empty login' => [['login' => ''], 'local_accounts_login'],
    'a bcrypt hash' => [['password_hash' => '$2y$12$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0'], 'local_accounts_password_hash'],
    'a password changed before the account' => [['password_changed_at' => '2026-03-10 11:00:00+00'], 'local_accounts_password_changed_at'],
    'version 0' => [['version' => 0], 'local_accounts_version'],
]);

it('refuses a reset token that is not a SHA-256, expires before it was made or was used after it expired', function (array $token, string $constraint): void {
    [$actor] = accountActors();
    identityConnection()->table(CredentialStore::table('local_accounts'))->insert([
        'actor_id' => $actor,
        'login' => 'ada@example.test',
        'password_hash' => ACCOUNT_HASH,
        'password_changed_at' => '2026-03-10 12:00:00+00',
        'version' => 1,
        'created_at' => '2026-03-10 12:00:00+00',
    ]);

    try {
        identityConnection()->table(CredentialStore::table('password_reset_tokens'))->insert([
            'token_hash' => hash('sha256', 'token'),
            'actor_id' => $actor,
            'expires_at' => '2026-03-10 13:00:00+00',
            'created_at' => '2026-03-10 12:00:00+00',
            ...$token,
        ]);
        Assert::fail("Expected the CHECK {$constraint} to refuse the row.");
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514')
            ->and($exception->getMessage())->toContain($constraint);
    }
})->with([
    'the token itself' => [['token_hash' => 'token'], 'password_reset_tokens_token_hash'],
    'upper case hex' => [['token_hash' => strtoupper(hash('sha256', 'token'))], 'password_reset_tokens_token_hash'],
    'an expiry before it was made' => [['expires_at' => '2026-03-10 11:00:00+00'], 'password_reset_tokens_expiry'],
    'used after it expired' => [['used_at' => '2026-03-10 14:00:00+00'], 'password_reset_tokens_used_at'],
]);

it('binds every local account to an actor of the actor register', function (): void {
    try {
        identityConnection()->table(CredentialStore::table('local_accounts'))->insert([
            'actor_id' => '0199c1f0-0000-7000-8000-000000000000',
            'login' => 'nobody@example.test',
            'password_hash' => ACCOUNT_HASH,
            'password_changed_at' => '2026-03-10 12:00:00+00',
            'version' => 1,
            'created_at' => '2026-03-10 12:00:00+00',
        ]);
        Assert::fail('Expected the foreign key to actors to refuse the account.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23503');
    }
});

it('keeps the login unique', function (): void {
    $row = static fn (string $actor): array => [
        'actor_id' => $actor,
        'login' => 'ada@example.test',
        'password_hash' => ACCOUNT_HASH,
        'password_changed_at' => '2026-03-10 12:00:00+00',
        'version' => 1,
        'created_at' => '2026-03-10 12:00:00+00',
    ];
    [$first, $second] = accountActors(2);
    identityConnection()->table(CredentialStore::table('local_accounts'))->insert($row($first));

    try {
        identityConnection()->table(CredentialStore::table('local_accounts'))->insert($row($second));
        Assert::fail('Expected local_accounts_login_key to refuse the second account.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23505')
            ->and($exception->getMessage())->toContain('local_accounts_login_key');
    }
});

it('gives the schema to the owner role, USAGE to the identity role alone, and the tables\' DML to the identity role alone', function (): void {
    $acl = static fn (string $sql): array => StorageTables::texts(DB::connection('pgsql_owner'), $sql);

    expect($acl("select pg_get_userbyid(nspowner)::text as value from pg_namespace where nspname = 'cms_identity'"))->toBe(['cms_owner'])
        ->and($acl("select grantee::regrole::text || ' ' || privilege_type as value from pg_namespace n, aclexplode(n.nspacl) where n.nspname = 'cms_identity' and grantee <> n.nspowner order by 1"))->toBe(['cms_identity USAGE'])
        ->and($acl(<<<'SQL'
            select c.relname || ' ' || case when a.grantee = 0 then 'PUBLIC' else a.grantee::regrole::text end || ' ' || a.privilege_type as value
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            cross join aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a
            where n.nspname = 'cms_identity' and c.relkind = 'r' and a.grantee <> c.relowner
            order by 1
            SQL))->toBe([
            'local_accounts cms_identity DELETE',
            'local_accounts cms_identity INSERT',
            'local_accounts cms_identity SELECT',
            'local_accounts cms_identity UPDATE',
            'password_reset_tokens cms_identity DELETE',
            'password_reset_tokens cms_identity INSERT',
            'password_reset_tokens cms_identity SELECT',
            'password_reset_tokens cms_identity UPDATE',
        ])
        ->and($acl("select c.relname || ' ' || c.relrowsecurity::text as value from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'cms_identity' and c.relkind = 'r' order by 1"))
        ->toBe(['local_accounts false', 'password_reset_tokens false']);
});

it('creates exactly the tables the module names, with an index on every foreign key', function (): void {
    $texts = static fn (string $sql): array => StorageTables::texts(DB::connection('pgsql_owner'), $sql);

    expect($texts("select c.relname::text as value from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'cms_identity' and c.relkind in ('r', 'p') order by 1"))->toBe(CredentialStore::TABLES)
        ->and($texts("select indexname::text as value from pg_indexes where schemaname = 'cms_identity' order by 1"))->toBe([
            'local_accounts_login_key',
            'local_accounts_pkey',
            'password_reset_tokens_actor_id',
            'password_reset_tokens_pkey',
        ])
        ->and(CredentialStore::SCHEMA)->toBe(TestDatabaseSetup::IDENTITY_SCHEMA);
});

it('points the identity connection at this checkout\'s database, and the harness empties the store after each test', function (): void {
    expect(identityConnection()->getDatabaseName())->toBe(DB::connection()->getDatabaseName())
        ->and(DB::connection()->getDatabaseName())->toStartWith('cms_test_')
        ->and(new OwnerTruncation(DB::connection('pgsql_owner'))->tables())->toContain('cms_identity.local_accounts', 'cms_identity.password_reset_tokens')
        ->and(identityConnection()->table(CredentialStore::table('local_accounts'))->count())->toBe(0);
});

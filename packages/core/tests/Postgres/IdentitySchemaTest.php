<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The identity tables as the migration leaves them (PRD 5.16, 4.2, 2.31): row level security,
 * forced so it holds for the owner, a read policy for every role and a write policy for the owner
 * alone, SELECT only for the app role, and an agent's ceiling kept at confidential by a check.
 */

const IDENTITY_TABLES = ['actors', 'service_credential_delegations', 'service_credentials'];

/**
 * The SQLSTATE of the query exception the call throws.
 */
/**
 * @param  Closure(): mixed  $call
 */
function identitySqlState(Closure $call): string
{
    try {
        $call();
    } catch (QueryException $exception) {
        $state = $exception->errorInfo[0] ?? null;

        return is_string($state) ? $state : throw new AssertionFailedError('The exception has no SQLSTATE.', $exception->getCode(), $exception);
    }

    throw new AssertionFailedError('The statement did not fail.');
}

it('forces row level security on every identity table, and postgres.row_security passes', function (): void {
    $owner = DB::connection('pgsql_owner');
    $rows = $owner->select(
        "select relname::text as name, relrowsecurity as enabled, relforcerowsecurity as forced from pg_class where relname = any (?::text[]) and relkind = 'r' order by relname",
        ['{'.implode(',', IDENTITY_TABLES).'}'],
    );
    $flags = array_map(static fn (mixed $row): array => is_object($row) ? get_object_vars($row) : [], $rows);
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect($flags)->toBe(array_map(static fn (string $table): array => ['name' => $table, 'enabled' => true, 'forced' => true], IDENTITY_TABLES))
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);
});

it('lets every role read and only the owner write', function (): void {
    $owner = DB::connection('pgsql_owner');
    $ownerRole = ReceiptTables::texts($owner, 'select current_user::text as value')[0];
    $policies = ReceiptTables::texts(
        $owner,
        "select tablename || ' ' || policyname || ' ' || cmd || ' ' || array_to_string(roles, ',') || ' ' || qual as value from pg_policies where tablename = any (?::text[]) order by tablename, policyname",
        ['{'.implode(',', IDENTITY_TABLES).'}'],
    );

    $expected = [];

    foreach (IDENTITY_TABLES as $table) {
        $expected[] = "{$table} {$table}_owner_write ALL {$ownerRole} true";
        $expected[] = "{$table} {$table}_read SELECT public true";
    }

    expect($policies)->toBe($expected);
});

it('gives the app role SELECT alone on the identity tables', function (): void {
    $app = DB::connection();

    foreach (IDENTITY_TABLES as $table) {
        foreach (['SELECT' => true, 'INSERT' => false, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false] as $privilege => $held) {
            expect($app->scalar('select has_table_privilege(current_user, ?::regclass, ?)', [$table, $privilege]))->toBe($held, "{$privilege} on {$table}");
        }
    }

    $actor = PostgresIdentity::at(new FakeClock)->addActor(ActorClass::Staff);

    expect(identitySqlState(fn () => $app->table('actors')->where('id', $actor->id->toString())->update(['state' => 'deactivated'])))->toBe('42501')
        ->and($app->table('actors')->where('id', $actor->id->toString())->value('state'))->toBe('active');
});

it('refuses an agent credential above confidential in the table itself', function (): void {
    $clock = new FakeClock;
    $identity = PostgresIdentity::at($clock);
    $actor = $identity->addActor(ActorClass::Service);
    $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Agent, ClassificationAccess::Confidential, $clock->now()->modify('+1 day')));
    $owner = DB::connection('pgsql_owner');

    expect(identitySqlState(fn () => $owner->table('service_credentials')->update(['classification_ceiling' => 'personal'])))->toBe('23514')
        ->and($owner->table('service_credentials')->value('classification_ceiling'))->toBe('confidential');
});

it('indexes every foreign key of the identity tables and keeps one credential per token hash', function (): void {
    $owner = DB::connection('pgsql_owner');
    $indexes = array_map(
        static fn (mixed $row): string => is_object($row) && isset($row->def) && is_string($row->def) ? $row->def : '',
        $owner->select("select regexp_replace(indexdef, ' ON [a-z0-9_.]+\\.', ' ON ') as def from pg_indexes where tablename in ('service_credentials', 'service_credential_delegations') order by indexname"),
    );

    expect($indexes)->toBe([
        'CREATE INDEX service_credential_delegations_actor_id ON service_credential_delegations USING btree (actor_id)',
        'CREATE UNIQUE INDEX service_credential_delegations_once ON service_credential_delegations USING btree (credential_id, actor_id)',
        'CREATE UNIQUE INDEX service_credential_delegations_pkey ON service_credential_delegations USING btree (credential_id, "position")',
        'CREATE INDEX service_credentials_actor_id ON service_credentials USING btree (actor_id)',
        'CREATE UNIQUE INDEX service_credentials_pkey ON service_credentials USING btree (id)',
        'CREATE UNIQUE INDEX service_credentials_secret_hash_key ON service_credentials USING btree (secret_hash)',
    ]);
});

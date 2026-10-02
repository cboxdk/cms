<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutConnections;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\PostgresHarness;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Illuminate\Config\Repository;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The Postgres harness and the credential store of the local accounts (PRD 5.16): it reads the
 * identity role's connection from cbox-cms.identity.connection, points it at the checkout's or the
 * worker's database with the others, and refuses an owner connection whose search path does not
 * reach the store's schema, which migrate:fresh and the truncation after each test need.
 */

function harnessOwner(string $searchPath): ConnectionSettings
{
    return new ConnectionSettings('pgsql_owner', '127.0.0.1', 5432, 'cms_test', 'cms_owner', 'secret', $searchPath);
}

it('reads the identity connection from cbox-cms.identity.connection, and fails when it names none', function (): void {
    expect(PostgresHarness::identityConnection(new Repository(['cbox-cms' => ['identity' => ['connection' => 'pgsql_identity']]])))->toBe('pgsql_identity')
        ->and(static fn (): string => PostgresHarness::identityConnection(new Repository([])))->toThrow(AssertionFailedError::class, 'cbox-cms.identity.connection names no database connection')
        ->and(static fn (): string => PostgresHarness::identityConnection(new Repository(['cbox-cms' => ['identity' => ['connection' => '']]])))->toThrow(AssertionFailedError::class);
});

it('accepts an owner search path that lists the credential store after the kernel\'s schema', function (string $searchPath): void {
    PostgresHarness::assertOwnerReachesCredentialStore(harnessOwner($searchPath));

    expect(true)->toBeTrue();
})->with(['cms,cms_identity', 'cms, "cms_identity"', 'cms, cms_identity, extra']);

it('refuses an owner search path that does not reach the credential store after the kernel\'s schema', function (string $searchPath): void {
    expect(static fn () => PostgresHarness::assertOwnerReachesCredentialStore(harnessOwner($searchPath)))
        ->toThrow(AssertionFailedError::class, 'It must list the kernel\'s schema first and cms_identity after it');
})->with(['cms', 'cms_identity,cms', 'cms,cms_identity_old']);

it('points the identity connection at the checkout\'s database and the worker\'s with the others', function (?int $worker): void {
    $root = dirname(__DIR__, 4);
    $connection = static fn (string $username, string $schema): array => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'cms_test', 'username' => $username, 'search_path' => $schema];
    $config = new Repository(['database' => ['default' => 'pgsql', 'connections' => [
        'pgsql' => $connection('cms_app', 'cms'),
        'pgsql_identity' => $connection('cms_identity', 'cms_identity'),
    ]]]);

    $database = CheckoutConnections::pointAt($config, $root, $worker);

    expect($database)->toBe(TestDatabaseName::for('cms_test', $root, $worker))
        ->and($config->get('database.connections.pgsql_identity.database'))->toBe($database);
})->with(['the checkout' => [null], 'a worker' => [3]]);

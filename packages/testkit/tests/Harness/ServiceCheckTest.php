<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\ServiceCheck;
use LogicException;

/*
 * The fail-fast check of the Postgres suite. It needs no service: it probes a port where
 * nothing listens.
 */

function unreachable(): ConnectionSettings
{
    return new ConnectionSettings(
        name: 'pgsql',
        host: '127.0.0.1',
        port: 1,
        database: 'cms_test',
        username: 'cms_app',
        password: 'secret-password',
        searchPath: 'cms',
    );
}

it('fails within seconds when nothing listens, and points to composer services:up', function (): void {
    $started = hrtime(true);
    $failure = ServiceCheck::probe(unreachable());
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($failure)->toBeString()
        ->toContain('The Postgres test service is not reachable at 127.0.0.1:1 (database cms_test, role cms_app, connection [pgsql]).')
        ->toContain('Reason: SQLSTATE[08006]')
        ->toContain('`composer services:up`')
        ->and($failure)->not->toContain('secret-password')
        ->and($seconds)->toBeLessThan(ServiceCheck::CONNECT_TIMEOUT_SECONDS + 1.0);
});

it('reads a pgsql connection from the configuration', function (): void {
    $settings = ConnectionSettings::of('pgsql', config());

    expect($settings->username)->toBe('cms_app')
        ->and($settings->database)->toBe('cms_test')
        ->and($settings->searchPath)->toBe('cms')
        ->and($settings->dsn(2))->toEndWith(';dbname=cms_test;connect_timeout=2')
        ->and(ConnectionSettings::of('pgsql_owner', config())->username)->toBe('cms_owner');
});

it('refuses a connection that is missing or not pgsql', function (string $connection, string $message): void {
    expect(static fn (): ConnectionSettings => ConnectionSettings::of($connection, config()))->toThrow(LogicException::class, $message);
})->with([
    'missing' => ['no_such_connection', 'The database connection [no_such_connection] is not configured.'],
    'sqlite' => ['sqlite', 'The database connection [sqlite] is not a pgsql connection.'],
]);

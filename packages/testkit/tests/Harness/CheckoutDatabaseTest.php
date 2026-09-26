<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutConnections;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabasePayload;
use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseMain;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Testkit\Postgres\TestDatabaseUnavailable;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Closure;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;

/*
 * Each checkout's own test database, the parts that need no service: pointing the connections,
 * the comment, the payload of the provisioning child, and the fail-fast message when the server
 * does not answer. The Postgres suite provisions for real (packages/testkit/tests/Postgres).
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function ownerAt(int $port): ConnectionSettings
{
    return new ConnectionSettings('pgsql_owner', '127.0.0.1', $port, 'cms_test', 'cms_owner', 'secret-password', 'cms');
}

function appAt(int $port): ConnectionSettings
{
    return new ConnectionSettings('pgsql', '127.0.0.1', $port, 'cms_test', 'cms_app', 'secret-password', 'cms');
}

final class CollectedOutput
{
    public string $text = '';

    /**
     * @return Closure(string): void
     */
    public function writer(): Closure
    {
        return function (string $text): void {
            $this->text .= $text;
        };
    }
}

/**
 * @param  array<string, mixed>  $connections
 */
function databaseConfig(array $connections, string $default = 'pgsql'): Repository
{
    return new Repository(['database' => ['default' => $default, 'connections' => $connections]]);
}

it('points every pgsql connection that names the configured database at the checkout\'s database, and only those', function (): void {
    $root = ScratchDirectory::make('cbox-cms-checkout-test-');
    $config = databaseConfig([
        'pgsql' => ['driver' => 'pgsql', 'database' => 'cms_test'],
        'pgsql_owner' => ['driver' => 'pgsql', 'database' => 'cms_test', 'username' => 'cms_owner'],
        'pgsql_other' => ['driver' => 'pgsql', 'database' => 'cms'],
        'sqlite' => ['driver' => 'sqlite', 'database' => 'cms_test'],
    ]);
    $derived = TestDatabaseName::for('cms_test', $root);

    expect(CheckoutConnections::point($config, $root))->toBe($derived)
        ->and($config->get('database.connections.pgsql.database'))->toBe($derived)
        ->and($config->get('database.connections.pgsql_owner.database'))->toBe($derived)
        ->and($config->get('database.connections.pgsql_owner.username'))->toBe('cms_owner')
        ->and($config->get('database.connections.pgsql_other.database'))->toBe('cms')
        ->and($config->get('database.connections.sqlite.database'))->toBe('cms_test')
        ->and(CheckoutConnections::point($config, $root))->toBe($derived)
        ->and($config->get('database.connections.pgsql.database'))->toBe($derived);
});

it('points nothing when the default connection is not pgsql', function (): void {
    $config = databaseConfig(['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'], 'pgsql' => ['driver' => 'pgsql', 'database' => 'cms_test']], 'sqlite');

    expect(CheckoutConnections::point($config, CheckoutRoot::current()))->toBeNull()
        ->and($config->get('database.connections.pgsql.database'))->toBe('cms_test')
        ->and(CheckoutConnections::point(new Repository, CheckoutRoot::current()))->toBeNull();
});

it('records the real path of the checkout and the host name as JSON in the comment', function (): void {
    $scratch = ScratchDirectory::make('cbox-cms-checkout-test-');
    mkdir($scratch.'/checkout');
    symlink($scratch.'/checkout', $scratch.'/link');
    $comment = TestDatabaseComment::of($scratch.'/link');

    expect($comment->encode())->toBe(sprintf('{"checkout":"%s/checkout","host":"%s"}', $scratch, gethostname()))
        ->and(TestDatabaseComment::decode($comment->encode()))->toEqual($comment);
});

it('refuses a comment that is not one', function (string $json, string $message): void {
    expect(static fn (): TestDatabaseComment => TestDatabaseComment::decode($json))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not JSON' => ['{', 'not valid JSON'],
    'no host' => ['{"checkout":"/srv/a"}', 'has no checkout and host'],
    'empty checkout' => ['{"checkout":"","host":"h"}', 'has no checkout and host'],
    'a list' => ['["/srv/a","h"]', 'has no checkout and host'],
]);

it('carries both connections and the root to the provisioning child, and refuses anything else', function (): void {
    $payload = new TestDatabasePayload(ownerAt(5432), appAt(5432), '/srv/checkout');

    expect(TestDatabasePayload::decode($payload->encode()))->toEqual($payload)
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode('{'))->toThrow(InvalidArgumentException::class, 'not valid JSON')
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode('1'))->toThrow(InvalidArgumentException::class, 'not an object')
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode('{"owner":{},"app":{}}'))->toThrow(InvalidArgumentException::class, 'has no root')
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode('{"root":"/srv"}'))->toThrow(InvalidArgumentException::class, 'has no connection');
});

it('fails fast when the server does not answer, naming the checkout\'s database and composer services:up', function (): void {
    $root = ScratchDirectory::make('cbox-cms-checkout-test-');
    $started = hrtime(true);

    try {
        TestDatabase::provision(ownerAt(1), appAt(1), $root);
    } catch (TestDatabaseUnavailable $exception) {
        $message = $exception->getMessage();

        expect($message)->toContain(
            'The Postgres test service is not reachable at 127.0.0.1:1 (database cms_test, role cms_app, connection [pgsql]).',
            'The tests of this checkout run in the database '.TestDatabaseName::for('cms_test', $root).', which the harness creates on that server.',
            '`composer services:up`',
        )
            ->and($message)->not->toContain('secret-password')
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(3.0);

        return;
    }

    Assert::fail('provision() succeeded against a closed port.');
});

it('keeps a failure for the rest of the process, so every later test fails at once with it', function (): void {
    $root = ScratchDirectory::make('cbox-cms-checkout-test-');
    $name = TestDatabaseName::for('cms_test', $root);

    expect(static fn (): string => TestDatabase::ensure(ownerAt(1), appAt(1), $root))
        ->toThrow(AssertionFailedError::class, 'run in the database '.$name);

    $started = hrtime(true);

    expect(static fn (): string => TestDatabase::ensure(ownerAt(1), appAt(1), $root))
        ->toThrow(AssertionFailedError::class, 'run in the database '.$name)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.1);
});

it('exits 2 from the provisioning child on an invalid payload and 1 with the reason when provisioning fails', function (): void {
    $root = ScratchDirectory::make('cbox-cms-checkout-test-');
    $out = new CollectedOutput;
    $err = new CollectedOutput;

    expect(TestDatabaseMain::run('{', $out->writer(), $err->writer()))->toBe(TestDatabaseMain::INVALID)
        ->and($err->text)->toContain('not valid JSON');

    $err = new CollectedOutput;
    $payload = new TestDatabasePayload(ownerAt(1), appAt(1), $root);

    expect(TestDatabaseMain::run($payload->encode(), $out->writer(), $err->writer()))->toBe(TestDatabaseMain::FAILED)
        ->and($err->text)->toContain(TestDatabaseUnavailable::class.': The Postgres test service is not reachable')
        ->and($out->text)->toBe('');
});

it('runs the provisioning child with the autoloader that loads the testkit, and exits 2 without one', function (): void {
    $command = TestDatabase::command();

    expect($command[0])->toBe(PHP_BINARY)
        ->and($command[1])->toBeFile()->toEndWith('/packages/testkit/bin/test-database.php')
        ->and($command[2])->toBe(CheckoutRoot::vendorDirectory().'/autoload.php');

    $process = new Process([PHP_BINARY, $command[1]]);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage: php test-database.php <path to vendor/autoload.php>');
});

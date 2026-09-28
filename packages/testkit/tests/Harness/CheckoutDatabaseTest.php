<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutConnections;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabasePayload;
use Cbox\Cms\Testkit\Postgres\Boundary\TestWorker;
use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseMain;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Testkit\Postgres\TestDatabaseUnavailable;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
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

it('points the connections of a parallel worker at the worker\'s database, also those already pointed at the checkout\'s or another worker\'s', function (): void {
    $root = ScratchDirectory::make('cbox-cms-checkout-test-');
    $config = databaseConfig([
        'pgsql' => ['driver' => 'pgsql', 'database' => 'cms_test'],
        'pgsql_owner' => ['driver' => 'pgsql', 'database' => TestDatabaseName::for('cms_test', $root)],
        'pgsql_worker' => ['driver' => 'pgsql', 'database' => TestDatabaseName::for('cms_test', $root, 7)],
        'pgsql_other' => ['driver' => 'pgsql', 'database' => 'cms'],
    ]);
    $worker = TestDatabaseName::for('cms_test', $root, 2);

    expect(CheckoutConnections::point($config, $root, 2))->toBe($worker)
        ->and($config->get('database.connections.pgsql.database'))->toBe($worker)
        ->and($config->get('database.connections.pgsql_owner.database'))->toBe($worker)
        ->and($config->get('database.connections.pgsql_worker.database'))->toBe($worker)
        ->and($config->get('database.connections.pgsql_other.database'))->toBe('cms')
        ->and(CheckoutConnections::point($config, $root, 2))->toBe($worker)
        ->and(CheckoutConnections::point($config, $root))->toBe(TestDatabaseName::for('cms_test', $root))
        ->and($config->get('database.connections.pgsql_owner.database'))->toBe(TestDatabaseName::for('cms_test', $root));
});

it('reads the parallel worker from TEST_TOKEN, none outside a parallel run, and refuses a parallel run without a valid token', function (): void {
    expect(TestWorker::of([]))->toBeNull()
        ->and(TestWorker::of(['TEST_TOKEN' => '']))->toBeNull()
        ->and(TestWorker::of(['PARATEST' => '1', 'TEST_TOKEN' => '3']))->toBe(3)
        ->and(TestWorker::of(['TEST_TOKEN' => '12']))->toBe(12)
        ->and(static fn (): ?int => TestWorker::of(['PARATEST' => '1']))->toThrow(InvalidArgumentException::class, 'without TEST_TOKEN')
        ->and(static fn (): ?int => TestWorker::of(['PARATEST' => '1', 'TEST_TOKEN' => '']))->toThrow(InvalidArgumentException::class, 'without TEST_TOKEN')
        ->and(static fn (): ?int => TestWorker::of(['TEST_TOKEN' => '0']))->toThrow(InvalidArgumentException::class, 'TEST_TOKEN is "0", not a positive integer.')
        ->and(static fn (): ?int => TestWorker::of(['TEST_TOKEN' => '02']))->toThrow(InvalidArgumentException::class, 'not a positive integer')
        ->and(static fn (): ?int => TestWorker::of(['TEST_TOKEN' => '1_abc']))->toThrow(InvalidArgumentException::class, 'not a positive integer');
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
    $worker = new TestDatabasePayload(ownerAt(5432), appAt(5432), '/srv/checkout', 3);

    expect(TestDatabasePayload::decode($payload->encode()))->toEqual($payload)
        ->and(TestDatabasePayload::decode($worker->encode()))->toEqual($worker)
        ->and(TestDatabasePayload::decode($worker->encode())->worker)->toBe(3)
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode(str_replace('"worker":3', '"worker":0', $worker->encode())))->toThrow(InvalidArgumentException::class, 'names a worker that is not a positive integer')
        ->and(static fn (): TestDatabasePayload => TestDatabasePayload::decode(str_replace('"worker":3', '"worker":"3"', $worker->encode())))->toThrow(InvalidArgumentException::class, 'names a worker that is not a positive integer')
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

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabasePayload;
use Cbox\Cms\Testkit\Postgres\Boundary\TestWorker;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\Infrastructure\PostgresTestDatabases;
use Cbox\Cms\Testkit\Postgres\Infrastructure\SetupStatement;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseMain;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Testkit\Postgres\TestDatabaseUnavailable;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
 * Each checkout's own test database against the Postgres 18 service (GUARDRAILS 9; PRD 4.2):
 * provisioning in a child process for another checkout root, its set-up as database.sql has it,
 * two provisioners at once, the fail-fast check of CREATEDB, and the drop. This checkout's
 * database is never touched.
 */

afterEach(function (): void {
    foreach (ScratchCheckouts::$roots as $root) {
        TestDatabase::drop(baseOwner(), $root);

        foreach (ScratchCheckouts::$workers as $worker) {
            TestDatabase::drop(baseOwner(), $root, $worker);
        }
    }

    ScratchCheckouts::$roots = [];
    ScratchCheckouts::$workers = [];
    ScratchDirectory::cleanUp();
});

/**
 * The configured database, cms_test, that this checkout's database was derived from.
 */
function baseDatabase(): string
{
    return TestDatabaseName::base(ConnectionSettings::of('pgsql', config())->database, CheckoutRoot::current());
}

function baseOwner(): ConnectionSettings
{
    return ConnectionSettings::of('pgsql_owner', config())->withDatabase(baseDatabase());
}

function baseApp(): ConnectionSettings
{
    return ConnectionSettings::of('pgsql', config())->withDatabase(baseDatabase());
}

/**
 * Starts bin/test-database.php for $root, or for its parallel worker $worker, with the owner and
 * app roles of the configured database.
 */
function provisionInChild(string $root, ?int $worker = null): Process
{
    if ($worker !== null) {
        ScratchCheckouts::$workers[] = $worker;
    }

    $process = new Process(TestDatabase::command());
    $process->setInput(new TestDatabasePayload(baseOwner(), baseApp(), $root, $worker)->encode());
    $process->setTimeout(60);
    $process->start();

    return $process;
}

/**
 * A connection of the application to $database as the role of $from.
 */
function connectionTo(string $database, string $from): Connection
{
    $name = $from.'_scratch';
    config(['database.connections.'.$name => array_merge((array) config('database.connections.'.$from), ['database' => $database])]);
    DB::purge($name);

    return DB::connection($name);
}

function databaseExists(string $database): bool
{
    return DB::connection('pgsql_owner')->scalar('select exists (select from pg_database where datname = ?)', [$database]) === true;
}

/**
 * This checkout's database as the catalog has it, to show it is untouched.
 *
 * @return array<array-key, mixed>
 */
function checkoutDatabase(): array
{
    return (array) DB::connection('pgsql_owner')->selectOne(
        "select oid::text as oid, datname, shobj_description(oid, 'pg_database') as comment from pg_database where datname = current_database()",
    );
}

it('provisions the database of another checkout root in a child process, migrates it, and provisions it again after a drop', function (): void {
    $before = checkoutDatabase();
    $root = ScratchCheckouts::make();
    $name = TestDatabaseName::for(baseDatabase(), $root);

    $child = provisionInChild($root);
    $child->wait();

    expect($child->getExitCode())->toBe(0, $child->getErrorOutput())
        ->and($child->getOutput())->toBe($name."\n")
        ->and($name)->not->toBe(config('database.connections.pgsql.database'))
        ->and($name)->toMatch('/\Acms_test_[0-9a-f]{12}\z/')
        ->and(databaseExists($name))->toBeTrue();

    $comment = DB::connection('pgsql_owner')->scalar("select shobj_description(oid, 'pg_database') from pg_database where datname = ?", [$name]);

    expect(TestDatabaseComment::decode(is_string($comment) ? $comment : ''))->toEqual(new TestDatabaseComment((string) realpath($root), (string) gethostname()));

    $owner = connectionTo($name, 'pgsql_owner');
    $status = app(Kernel::class)->call('migrate', ['--database' => 'pgsql_owner_scratch', '--force' => true]);
    $app = connectionTo($name, 'pgsql');

    expect($status)->toBe(0, app(Kernel::class)->output())
        ->and($owner->scalar('select current_user'))->toBe('cms_owner')
        ->and($owner->scalar("select tableowner from pg_tables where schemaname = 'cms' and tablename = 'migrations'"))->toBe('cms_owner')
        ->and($app->scalar('select current_user'))->toBe('cms_app')
        ->and($app->table('migrations')->count())->toBe($owner->table('migrations')->count())
        ->and($app->table('migrations')->count())->toBeGreaterThan(0);

    try {
        $app->statement('create table scratch_ddl_probe (x int)');
        Assert::fail('The app role created a table in the provisioned database.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501');
    }

    DB::purge('pgsql_owner_scratch');
    DB::purge('pgsql_scratch');

    config(['database.connections.pgsql_owner_base' => array_merge((array) config('database.connections.pgsql_owner'), ['database' => baseDatabase()])]);
    [$base] = app(IndependentConnections::class)->open(1, 'pgsql_owner_base');

    expect($base->scalar('select current_database()'))->toBe(baseDatabase());

    $base->statement('drop database '.$base->getQueryGrammar()->wrap($name));

    expect(databaseExists($name))->toBeFalse();

    $again = provisionInChild($root);
    $again->wait();

    expect($again->getExitCode())->toBe(0, $again->getErrorOutput())
        ->and($again->getOutput())->toBe($name."\n")
        ->and(databaseExists($name))->toBeTrue()
        ->and(TestDatabase::drop(baseOwner(), $root))->toBeTrue()
        ->and(databaseExists($name))->toBeFalse()
        ->and(TestDatabase::drop(baseOwner(), $root))->toBeFalse()
        ->and(checkoutDatabase())->toBe($before);
});

it('sets the database up as database.sql does: owned by the owner, CONNECT for the app role only, the schema and the default privileges', function (): void {
    $root = ScratchCheckouts::make();
    $name = TestDatabase::provision(baseOwner(), baseApp(), $root);
    $owner = connectionTo($name, 'pgsql_owner');
    $query = static fn (Connection $connection, string $sql): array => array_map(
        static fn (mixed $row): array => (array) $row,
        $connection->select($sql),
    );

    expect($query($owner, 'select pg_get_userbyid(datdba) as owner, datacl::text as acl from pg_database where datname = current_database()'))
        ->toBe($query(DB::connection('pgsql_owner'), 'select pg_get_userbyid(datdba) as owner, datacl::text as acl from pg_database where datname = current_database()'))
        ->toBe([['owner' => 'cms_owner', 'acl' => '{cms_owner=CTc/cms_owner,cms_app=c/cms_owner}']])
        ->and($query($owner, "select nspname as schema, pg_get_userbyid(nspowner) as owner, nspacl::text as acl from pg_namespace where nspname in ('cms', 'public') order by 1"))
        ->toBe([
            ['schema' => 'cms', 'owner' => 'cms_owner', 'acl' => '{cms_owner=UC/cms_owner,cms_app=U/cms_owner}'],
            ['schema' => 'public', 'owner' => 'pg_database_owner', 'acl' => '{pg_database_owner=UC/pg_database_owner,=U/pg_database_owner}'],
        ])
        ->and($query($owner, 'select defaclrole::regrole::text as role, defaclnamespace::regnamespace::text as schema, defaclobjtype as type, defaclacl::text as acl from pg_default_acl order by 3'))
        ->toBe([
            ['role' => 'cms_owner', 'schema' => 'cms', 'type' => 'S', 'acl' => '{cms_app=rU/cms_owner}'],
            ['role' => 'cms_owner', 'schema' => 'cms', 'type' => 'r', 'acl' => '{cms_app=arwd/cms_owner}'],
        ]);

    DB::purge('pgsql_owner_scratch');
});

it('lets two child processes provision the same checkout at once: both exit 0 and one database exists', function (): void {
    $root = ScratchCheckouts::make();
    $name = TestDatabaseName::for(baseDatabase(), $root);

    // Hold the provisioners' lock, so both children are waiting on it at the same time.
    config(['database.connections.pgsql_owner_base' => array_merge((array) config('database.connections.pgsql_owner'), ['database' => baseDatabase()])]);
    [$holder] = app(IndependentConnections::class)->open(1, 'pgsql_owner_base');
    $holder->beginTransaction();
    $holder->select('select pg_advisory_xact_lock(?)', [PostgresTestDatabases::lockKey($name)]);

    $first = provisionInChild($root);
    $second = provisionInChild($root);
    usleep(1_000_000);

    expect($first->isRunning())->toBeTrue($first->getErrorOutput())
        ->and($second->isRunning())->toBeTrue($second->getErrorOutput())
        ->and(databaseExists($name))->toBeFalse();

    $holder->commit();
    $first->wait();
    $second->wait();

    expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
        ->and($second->getExitCode())->toBe(0, $second->getErrorOutput())
        ->and($first->getOutput())->toBe($name."\n")
        ->and($second->getOutput())->toBe($name."\n")
        ->and(DB::connection('pgsql_owner')->scalar('select count(*) from pg_database where datname = ?', [$name]))->toBe(1);
});

it('provisions the databases of two parallel workers of one checkout at once, under the checkout\'s lock, and neither sees the other\'s rows', function (): void {
    $root = ScratchCheckouts::make();
    $names = [1 => TestDatabaseName::for(baseDatabase(), $root, 1), 2 => TestDatabaseName::for(baseDatabase(), $root, 2)];

    // Hold the lock of the checkout's database, so both workers' provisioners wait on it together.
    config(['database.connections.pgsql_owner_base' => array_merge((array) config('database.connections.pgsql_owner'), ['database' => baseDatabase()])]);
    [$holder] = app(IndependentConnections::class)->open(1, 'pgsql_owner_base');
    $holder->beginTransaction();
    $holder->select('select pg_advisory_xact_lock(?)', [PostgresTestDatabases::lockKey(TestDatabaseName::for(baseDatabase(), $root))]);

    $children = [1 => provisionInChild($root, 1), 2 => provisionInChild($root, 2)];
    usleep(1_000_000);

    expect($children[1]->isRunning())->toBeTrue($children[1]->getErrorOutput())
        ->and($children[2]->isRunning())->toBeTrue($children[2]->getErrorOutput())
        ->and(databaseExists($names[1]))->toBeFalse()
        ->and(databaseExists($names[2]))->toBeFalse();

    $holder->commit();

    foreach ($children as $worker => $child) {
        $child->wait();

        expect($child->getExitCode())->toBe(0, $child->getErrorOutput())
            ->and($child->getOutput())->toBe($names[$worker]."\n")
            ->and(databaseExists($names[$worker]))->toBeTrue();
    }

    expect(databaseExists(TestDatabaseName::for(baseDatabase(), $root)))->toBeFalse();

    // Each worker writes a row of its own to the same table in its own database, as two workers
    // running the same test would.
    foreach ($names as $worker => $name) {
        connectionTo($name, 'pgsql_owner')->statement('create table worker_rows (worker int not null)');
        DB::purge('pgsql_owner_scratch');

        connectionTo($name, 'pgsql')->table('worker_rows')->insert(['worker' => $worker]);
        DB::purge('pgsql_scratch');
    }

    foreach ($names as $worker => $name) {
        $app = connectionTo($name, 'pgsql');

        expect($app->scalar('select current_database()'))->toBe($name)
            ->and($app->table('worker_rows')->pluck('worker')->all())->toBe([$worker]);

        DB::purge('pgsql_scratch');
    }
});

it('provisions under the advisory lock the caller names, and gives up when the lock timeout passes', function (): void {
    $root = ScratchCheckouts::make();
    ScratchCheckouts::$workers[] = 1;
    $checkout = TestDatabaseName::for(baseDatabase(), $root);
    $worker = TestDatabaseName::for(baseDatabase(), $root, 1);
    $app = baseApp();
    $setup = new TestDatabaseSetup($worker, baseOwner()->username, $app->username, $app->searchPath);
    $databases = new PostgresTestDatabases(baseOwner(), 2, '200ms');

    // Hold the lock of the checkout's database, which the worker's set-up names.
    config(['database.connections.pgsql_owner_base' => array_merge((array) config('database.connections.pgsql_owner'), ['database' => baseDatabase()])]);
    [$holder] = app(IndependentConnections::class)->open(1, 'pgsql_owner_base');
    $holder->beginTransaction();
    $holder->select('select pg_advisory_xact_lock(?)', [PostgresTestDatabases::lockKey($checkout)]);

    try {
        $databases->provision($setup, TestDatabaseComment::of($root), $checkout);
        Assert::fail('provision() ran its set-up while another session held the lock it names.');
    } catch (PDOException $exception) {
        expect($exception->errorInfo[0] ?? null)->toBe('55P03')
            ->and($exception->getMessage())->toContain('lock timeout')
            ->and(databaseExists($worker))->toBeFalse();
    }

    $holder->commit();
    $databases->provision($setup, TestDatabaseComment::of($root), $checkout);

    expect(databaseExists($worker))->toBeTrue()
        ->and($databases->exists($worker))->toBeTrue()
        ->and(databaseExists($checkout))->toBeFalse()
        ->and($databases->exists($checkout))->toBeFalse()
        ->and($databases->canCreateDatabases())->toBeTrue();
});

it('fails fast when the role that provisions lacks CREATEDB, naming the role, CREATEDB, the database and composer services:up', function (): void {
    $root = ScratchCheckouts::make();
    $name = TestDatabaseName::for(baseDatabase(), $root);
    $app = baseApp();

    // The app role connects to the configured database but has no CREATEDB, as an owner role
    // provisioned before CREATEDB was granted.
    expect(static fn (): string => TestDatabase::provision($app, $app, $root))
        ->toThrow(TestDatabaseUnavailable::class, "The owner role cms_app has no CREATEDB at {$app->host}:{$app->port}, so the harness cannot create this checkout's test database {$name}.\nRun `composer services:up`, which provisions the roles again and gives the owner role CREATEDB, and run the suite again.")
        ->and(databaseExists($name))->toBeFalse();
});

it('refuses a search path that names more than one schema, before it creates anything', function (): void {
    $root = ScratchCheckouts::make();
    $app = baseApp();
    $several = new ConnectionSettings($app->name, $app->host, $app->port, $app->database, $app->username, $app->password, 'cms, public');

    expect(static fn (): string => TestDatabase::provision(baseOwner(), $several, $root))
        ->toThrow(TestDatabaseUnavailable::class, 'The search path of the connection [pgsql] is "cms, public". The harness sets up one schema')
        ->and(databaseExists(TestDatabaseName::for(baseDatabase(), $root)))->toBeFalse();
});

it('counts a CREATE DATABASE that finds the database already there as done, and fails on any other error', function (): void {
    $settings = baseOwner();
    $pdo = new PDO($settings->dsn(2), $settings->username, $settings->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $existing = ConnectionSettings::of('pgsql', config())->database;

    PostgresTestDatabases::execute($pdo, new SetupStatement(sprintf("SELECT format('CREATE DATABASE %%I', '%s')", $existing), gexec: true));

    expect(static fn () => PostgresTestDatabases::execute($pdo, new SetupStatement("SELECT 'CREATE DATABASE \"x\" WITH NO_SUCH_OPTION = 1'", gexec: true)))
        ->toThrow(PDOException::class, 'no_such_option')
        ->and(databaseExists($existing))->toBeTrue();
});

it('leaves this checkout\'s database to the harness, which provisioned it with the host and path of this checkout', function (): void {
    $comment = checkoutDatabase()['comment'] ?? null;

    expect(checkoutDatabase()['datname'] ?? null)->toBe(TestDatabaseName::for('cms_test', CheckoutRoot::current(), TestWorker::current()))
        ->and(TestDatabaseComment::decode(is_string($comment) ? $comment : ''))->toEqual(new TestDatabaseComment(CheckoutRoot::current(), (string) gethostname()));
});

/**
 * Runs the child's body in this process, as bin/test-database.php runs it, and gives its exit code,
 * standard output and standard error.
 *
 * @return array{int, string, string}
 */
function testDatabaseMain(string $input): array
{
    $out = '';
    $err = '';
    $code = TestDatabaseMain::run($input, static function (string $text) use (&$out): void {
        $out .= $text;
    }, static function (string $text) use (&$err): void {
        $err .= $text;
    });

    return [$code, $out, $err];
}

it('exits 0 with the database\'s name, 1 with the failure and 2 for a payload it cannot read, as bin/test-database.php does', function (): void {
    $root = ScratchCheckouts::make();
    $name = TestDatabaseName::for(baseDatabase(), $root);
    $unreachable = ConnectionSettings::fromPayload([...baseOwner()->toPayload(), 'port' => 1]);

    [$okCode, $okOut, $okErr] = testDatabaseMain(new TestDatabasePayload(baseOwner(), baseApp(), $root)->encode());
    [$failedCode, $failedOut, $failedErr] = testDatabaseMain(new TestDatabasePayload($unreachable, baseApp(), $root)->encode());
    [$invalidCode, $invalidOut, $invalidErr] = testDatabaseMain('{');

    expect([$okCode, $okOut, $okErr])->toBe([0, $name."\n", ''])
        ->and([$okCode, $failedCode, $invalidCode])->toBe([TestDatabaseMain::OK, TestDatabaseMain::FAILED, TestDatabaseMain::INVALID])
        ->and($failedCode)->toBe(1)
        ->and($failedOut)->toBe('')
        ->and($failedErr)->toStartWith(TestDatabaseUnavailable::class.': ')
        ->and($failedErr)->toEndWith("\n")
        ->and($invalidCode)->toBe(2)
        ->and($invalidOut)->toBe('')
        ->and($invalidErr)->toStartWith('The test database payload is not valid JSON: ')
        ->and(TestDatabase::drop(baseOwner(), $root))->toBeTrue();
});

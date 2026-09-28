---
title: Testing against real Postgres and Valkey
weight: 39
description: The testkit harnesses RealPostgres and RealValkey for tests that must see what the real services do.
---

# Testing against real Postgres and Valkey

<!-- extension-point: Cbox\Cms\Testkit\Postgres\RealPostgres -->
<!-- extension-point: Cbox\Cms\Testkit\Valkey\RealValkey -->

The testkit of `cboxdk/cms` has two harnesses for tests that need the real services (GUARDRAILS 9): `Cbox\Cms\Testkit\Postgres\RealPostgres` and `Cbox\Cms\Testkit\Valkey\RealValkey`. Both are traits for a Testbench test case. They are `#[Experimental]`: public API that an addon may use, without a compatibility promise yet, so they can change in a minor release. The kernel's own Postgres tests run on them, and so do the examples on this page.

A fake is the first choice for code that takes a contract, such as the receipt store or the clock. A test that must see what Postgres or Valkey really does, such as locks, row security, partitions, grants or key expiry, uses the harnesses instead.

## What the services must offer

The harnesses start no service. The test run needs Postgres and Valkey running, and a test fails at once, with the reason and the fix, when one does not answer.

- **Postgres 17 or later**, with two login roles (PRD 4.2), set up once per server by the test environment:
  - The owner role runs the migrations and partition maintenance and owns the test databases. It needs `CREATEDB`, because the harness creates each checkout's test database as that role.
  - The app role owns nothing and has no DDL, no `CREATEDB` and no `BYPASSRLS`.
  - Both roles can connect to the configured database (`DB_DATABASE`), and both have `lc_messages = 'C'`, because the kernel reads the text of some errors.
- **Valkey**, with database index 15 (`ValkeyRun::DATABASE`) set aside for tests.

The harness sets up each test database itself, as the owner role: the schema of the app connection's `search_path`, owned by the owner role, `CONNECT` on the database and `USAGE` on the schema for the app role, and default privileges that give the app role `SELECT`, `INSERT`, `UPDATE` and `DELETE` on the tables the migrations create. In this repository `composer services:up` starts both services from `compose.yaml`, and `docker/postgres/sql/roles.sql` creates the roles.

## The test case

The test case uses both traits. Testbench calls `setUpRealPostgres()` and `setUpRealValkey()` after the application has booted, and the tear-down methods before it is destroyed, so nothing else calls them. A PHPUnit test class extends the test case, as the example below does, and Pest gives it to a directory in `tests/Pest.php` with `pest()->extend(AddonTestCase::class)->in('Postgres')`.

The application needs two pgsql connections to the same database and schema: the default connection as the app role, and `pgsql_owner` as the owner role. A test case that calls its owner connection something else overrides `postgresOwnerConnection()`, and one whose Redis connection for the service check is not `default` overrides `valkeyConnection()`. A test case must not use `DatabaseTransactions`, `RefreshDatabase` or `LazilyRefreshDatabase`; the harness fails a test that does.

<!-- example-file: examples/Postgres/Harness/AddonTestCase.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Harness;

use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * The base class of an addon's tests against real Postgres and Valkey. The installed packages'
 * providers are discovered, as in an application, and WithWorkbench registers those of the
 * repository's testbench.yaml, which in cboxdk/cms's own repository, where cboxdk/cms is the root
 * package that discovery does not see, are cboxdk/cms's; so cboxdk/cms brings the core's
 * migrations and the partition command.
 *
 * The default connection `pgsql` is the app role and `pgsql_owner` the owner role, both on the
 * database and schema of the DB_* variables in phpunit.xml. The harness moves both to the
 * checkout's own test database, which it creates as the owner role. The Redis connections come
 * from the REDIS_* variables, as in any Laravel application.
 */
abstract class AddonTestCase extends TestCase
{
    use RealPostgres;
    use RealValkey;
    use WithWorkbench;

    /** Discover the service providers of the installed packages. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    #[Override]
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $appRole = [
            'driver' => 'pgsql',
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => $this->env('DB_PORT', '5432'),
            'database' => $this->env('DB_DATABASE', 'cms_test'),
            'username' => $this->env('DB_USERNAME', 'cms_app'),
            'password' => $this->env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => $this->env('DB_SCHEMA', 'cms'),
            'sslmode' => 'prefer',
        ];

        $config->set('database.default', 'pgsql');
        $config->set('database.connections.pgsql', $appRole);
        $config->set('database.connections.pgsql_owner', [
            ...$appRole,
            'username' => $this->env('DB_OWNER_USERNAME', 'cms_owner'),
            'password' => $this->env('DB_OWNER_PASSWORD', ''),
        ]);
    }

    private function env(string $key, string $default): string
    {
        $value = Env::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
```

## What a test gets

`RealPostgres` does this for each test:

- **Its own database.** Every pgsql connection that names the configured database is moved to the checkout's own test database, `<database>_<12 hex>`, from a hash of the path of the Composer root package, so two checkouts on one host never share rows. The harness creates that database as the owner role the first time a process needs it, and builds the schema once per process with `migrate:fresh` on the owner connection, with every migration the providers register.
- **The app role, and real commits.** The test runs as the app role on the default connection, with no transaction around it. What it writes is committed, so another connection sees it. DDL fails with SQLSTATE 42501, insufficient privilege, as it does for the application; the schema comes from the migrations, which run as the owner role.
- **Empty tables.** After the test the harness rolls back what the test left open, truncates every table as the owner role and disconnects every connection. Partitions a test created stay until the schema is rebuilt.
- **No nested transactions.** A transaction begun inside another, which Laravel turns into a savepoint, fails the test where it begins, and at tear-down if the test caught the failure (PRD 4.2, GUARDRAILS 4.1).
- **Helpers in the container.** `app(IndependentConnections::class)->open($count)` opens connections with their own backends, to see what another session sees or to hold a lock. `app(ChildProcesses::class)` runs a closure or a script in a separate PHP process, on the same database as the same role, for a caller that must block while another holds a lock. `app(PartitionFixtures::class)->cover($from, $to)` or `coverClock($clock, $ahead)` creates the partitions of the dates a test writes at, because a partitioned table has no DEFAULT partition. The harness closes the connections and stops the processes after each test.

`RealValkey` does this for each test:

- **Its own keys.** Every Redis connection uses database index 15 and a key prefix of the run, `cms_test_<run id>_`, so the Redis facade and the cache write under it and two runs never see each other's keys. `app(ValkeyRun::class)` is the run: `prefix`, `keys()` and `client()`.
- **No keys left.** After the test, and when the process ends, the harness removes the keys under the prefix with SCAN and UNLINK, never FLUSHDB, so the keys of other runs stay.

The example commits a receipt as the app role and sees it from another connection, shows that the app role has no DDL, and writes a key under the run prefix:

<!-- example: examples/Postgres/Harness/RealServicesTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Harness;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a test on AddonTestCase gets: it runs as the app role with no transaction around it, its
 * writes commit, and it starts with empty tables and no keys, because the harnesses truncate the
 * tables and remove the run's keys after each test.
 */
final class RealServicesTest extends AddonTestCase
{
    #[Test]
    public function a_write_commits_and_another_connection_sees_it(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
        app()->instance(Clock::class, $clock);
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        self::assertSame(0, DB::transactionLevel());
        self::assertSame(0, DB::table('receipts')->count());

        // The receipt store writes only in the caller's transaction, as the command kernel's is.
        DB::transaction(static function () use ($changesetId): void {
            app(ReceiptStore::class)->store(new StoredReceipt($changesetId, RetentionClass::Standard));
        });

        [$other] = app(IndependentConnections::class)->open(1);
        self::assertSame(1, $other->table('receipts')->where('changeset_id', $changesetId->toString())->count());
    }

    #[Test]
    public function the_test_runs_as_the_app_role_which_has_no_ddl(): void
    {
        // SQLSTATE 42501, insufficient_privilege: the app role has USAGE on the schema, not CREATE.
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
        });
    }

    #[Test]
    public function redis_keys_land_under_the_run_prefix(): void
    {
        $run = app(ValkeyRun::class);

        Redis::set('greeting', 'hello');

        self::assertSame('hello', Redis::get('greeting'));
        self::assertSame([$run->prefix.'greeting'], $run->keys());
    }
}
```

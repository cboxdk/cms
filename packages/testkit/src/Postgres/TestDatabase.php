<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Boundary\TestWorker;
use Cbox\Cms\Testkit\Postgres\Infrastructure\PostgresTestDatabases;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Each checkout's own Postgres test database (GUARDRAILS 9, real Postgres; PRD 4.2).
 *
 * The name is TestDatabaseName: the configured database plus a hash of the checkout's real path.
 * provision() is the one way it comes into being. It connects as the owner role to the
 * configured database, fails fast when the server does not answer or the owner role lacks
 * CREATEDB, creates the database when it is missing, owned by the owner role, sets it up as
 * docker/postgres/sql/database.sql does, with the role names and the schema of the connections it
 * is given, and records the checkout's real path and host in COMMENT ON DATABASE. Two processes
 * may provision the same database at once. The harness calls it through ensure() before the first
 * test of a process, and a child process can call it with the root of another checkout, through
 * bin/test-database.php (command()).
 *
 * Two processes of the same checkout share its database, as they shared the configured one
 * before; the harness truncates after each test either way. The workers of a parallel run do
 * not: each has a database of its own, TestDatabaseName::for(base, root, worker), which
 * provision() creates and sets up in the same way, under the advisory lock of the checkout's
 * database, so the set-ups of one checkout's databases run one at a time.
 */
#[Experimental]
final class TestDatabase
{
    /** @var array<string, string> the database of each ensure() that failed, and why */
    private static array $failures = [];

    /** @var array<string, true> the databases ensure() provisioned in this process */
    private static array $provisioned = [];

    /**
     * Makes sure the test database of the checkout at $root, or of its parallel worker $worker
     * when that is not null, exists and is set up, and returns its name. $owner and $app are the
     * owner role's and the app role's connections, to the configured database or already to the
     * checkout's or a worker's; the schema is the app connection's search path, which must name
     * exactly one schema and is checked before anything connects.
     *
     * @throws TestDatabaseUnavailable with the database, the role and the fix in the message
     */
    public static function provision(ConnectionSettings $owner, ConnectionSettings $app, string $root, ?int $worker = null): string
    {
        $schema = self::schema($app);
        $base = TestDatabaseName::base($owner->database, $root);
        $name = TestDatabaseName::for($base, $root, $worker);
        $server = $owner->withDatabase($base);

        $unreachable = ServiceCheck::probe($name, $app->withDatabase($base), $server);

        if ($unreachable !== null) {
            throw new TestDatabaseUnavailable($unreachable);
        }

        $databases = new PostgresTestDatabases($server, ServiceCheck::CONNECT_TIMEOUT_SECONDS);

        try {
            if (! $databases->canCreateDatabases()) {
                throw new TestDatabaseUnavailable(sprintf(
                    "The owner role %s has no CREATEDB at %s:%d, so the harness cannot create this checkout's test database %s.\n"
                    .'Run `composer services:up`, which provisions the roles again and gives the owner role CREATEDB, and run the suite again.',
                    $server->username,
                    $server->host,
                    $server->port,
                    $name,
                ));
            }

            $databases->provision(
                new TestDatabaseSetup($name, $server->username, $app->username, $schema),
                TestDatabaseComment::of($root),
                TestDatabaseName::for($base, $root),
            );
        } catch (PDOException $exception) {
            throw new TestDatabaseUnavailable(sprintf(
                "The harness could not set up this checkout's test database %s as the owner role %s at %s:%d.\nReason: %s",
                $name,
                $server->username,
                $server->host,
                $server->port,
                preg_replace('/\s+/', ' ', trim($exception->getMessage())),
            ), 0, $exception);
        }

        return $name;
    }

    /**
     * provision() once per process for the database of this process, as the harness needs it
     * before the first test: the checkout's at $root (by default CheckoutRoot::current()), or in
     * a worker of a parallel run the worker's (TestWorker::current()). A failure is kept, so every
     * later test of the process fails at once with its message.
     *
     * @throws AssertionFailedError with the message of the failure
     * @throws InvalidArgumentException when the process is a parallel worker without a valid TEST_TOKEN
     */
    public static function ensure(ConnectionSettings $owner, ConnectionSettings $app, ?string $root = null): string
    {
        $root ??= CheckoutRoot::current();
        $worker = TestWorker::current();
        $name = TestDatabaseName::for(TestDatabaseName::base($owner->database, $root), $root, $worker);

        if (isset(self::$failures[$name])) {
            throw new AssertionFailedError(self::$failures[$name]);
        }

        if (! isset(self::$provisioned[$name])) {
            try {
                self::provision($owner, $app, $root, $worker);
            } catch (TestDatabaseUnavailable $exception) {
                self::$failures[$name] = $exception->getMessage();

                throw new AssertionFailedError($exception->getMessage(), $exception->getCode(), $exception);
            }

            self::$provisioned[$name] = true;
        }

        return $name;
    }

    /**
     * Drops the test database of the checkout at $root, or of its parallel worker $worker when
     * that is not null, as the owner role connected to the configured database, and says whether
     * it existed. Nothing may be connected to it.
     *
     * @throws TestDatabaseUnavailable when the server does not answer or the drop fails
     */
    public static function drop(ConnectionSettings $owner, string $root, ?int $worker = null): bool
    {
        $base = TestDatabaseName::base($owner->database, $root);
        $name = TestDatabaseName::for($base, $root, $worker);

        try {
            return new PostgresTestDatabases($owner->withDatabase($base), ServiceCheck::CONNECT_TIMEOUT_SECONDS)->drop($name);
        } catch (PDOException $exception) {
            throw new TestDatabaseUnavailable(sprintf(
                "Could not drop the test database %s as the owner role %s at %s:%d.\nReason: %s",
                $name,
                $owner->username,
                $owner->host,
                $owner->port,
                preg_replace('/\s+/', ' ', trim($exception->getMessage())),
            ), 0, $exception);
        }
    }

    /**
     * The command of a child process that provisions a checkout's test database: the payload,
     * a TestDatabasePayload, goes on standard input, and the database's name comes back on
     * standard output.
     *
     * @return list<string>
     */
    public static function command(): array
    {
        return [PHP_BINARY, dirname(__DIR__, 2).'/bin/test-database.php', ChildProcesses::autoloader()];
    }

    private static function schema(ConnectionSettings $app): string
    {
        $schema = trim($app->searchPath);

        if ($schema === '' || str_contains($schema, ',')) {
            throw new TestDatabaseUnavailable(sprintf(
                'The search path of the connection [%s] is "%s". The harness sets up one schema, so the search path must name exactly one.',
                $app->name,
                $app->searchPath,
            ));
        }

        return $schema;
    }
}

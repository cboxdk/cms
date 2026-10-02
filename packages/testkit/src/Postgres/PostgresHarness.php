<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutConnections;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Infrastructure\OwnerTruncation;
use Cbox\Cms\Testkit\Postgres\Infrastructure\PartitionSweep;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * One Postgres test from set-up to tear-down (GUARDRAILS 9, real Postgres; PRD 4.2).
 *
 * Set-up checks that no transaction wraps the test, points every pgsql connection at the
 * checkout's own test database (TestDatabase), the identity connection of the credential store
 * included, provisions that database once per process and fails fast when the services are down
 * or the owner role lacks CREATEDB, checks that the owner connection's search path reaches the
 * credential store's schema, builds the schemas as the owner role once per process, and installs
 * the nested transaction guard. The test then
 * runs as the app role on the default connection, and its commits are real. Tear-down stops
 * child processes, closes independent connections, rolls back what the test left open, drops
 * every leaf partition (PartitionSweep) and truncates every table as the owner role, disconnects
 * every connection of the application,
 * and fails the test if the guard saw a nested transaction.
 *
 * The disconnect is what frees the test's backends. The application's object graph has cycles,
 * so without it a connection's PDO lives until PHP's cycle collector runs, and a suite that
 * opens owner and app connections test after test runs Postgres out of connection slots.
 *
 * Tests reach the helpers through the container: `app(IndependentConnections::class)`,
 * `app(ChildProcesses::class)` and `app(NestedTransactionGuard::class)`.
 */
#[Experimental]
final readonly class PostgresHarness
{
    /**
     * Traits that wrap each test in a transaction, which the Postgres suite never does.
     *
     * @var list<class-string>
     */
    public const array WRAPPING_TRAITS = [DatabaseTransactions::class, RefreshDatabase::class, LazilyRefreshDatabase::class];

    /** The configuration key that names the identity role's connection to the credential store. */
    public const string IDENTITY_CONNECTION_KEY = 'cbox-cms.identity.connection';

    private function __construct(
        private DatabaseManager $database,
        private string $ownerConnection,
        private NestedTransactionGuard $guard,
        private IndependentConnections $connections,
        private ChildProcesses $processes,
    ) {}

    /**
     * @param  class-string  $testClass
     */
    public static function start(?Application $app, string $testClass, string $ownerConnection): self
    {
        if (! $app instanceof Application) {
            throw new LogicException('The Postgres harness starts after the application has booted.');
        }

        $wrapping = array_values(array_intersect(self::WRAPPING_TRAITS, class_uses_recursive($testClass)));

        if ($wrapping !== []) {
            throw new AssertionFailedError(sprintf(
                '%s uses %s. Postgres tests never run inside a wrapping test transaction; the harness truncates the committed rows instead.',
                $testClass,
                implode(' and ', $wrapping),
            ));
        }

        $config = $app->make(Repository::class);
        $database = $app->make(DatabaseManager::class);

        foreach ($database->getConnections() as $name => $connection) {
            if ($connection->transactionLevel() > 0) {
                throw new AssertionFailedError(sprintf(
                    'The connection [%s] is inside a transaction before the test starts. Postgres tests never run inside a wrapping test transaction.',
                    $name,
                ));
            }
        }

        // Every pgsql connection reaches the checkout's own database; one opened before now is
        // closed, so it reconnects there.
        $root = CheckoutRoot::current();
        CheckoutConnections::point($config, $root);

        foreach (array_keys($database->getConnections()) as $name) {
            $database->purge($name);
        }

        $owner = ConnectionSettings::of($ownerConnection, $config);

        TestDatabase::ensure(
            $owner,
            ConnectionSettings::of($database->getDefaultConnection(), $config),
            ConnectionSettings::of(self::identityConnection($config), $config),
            $root,
        );

        self::assertOwnerReachesCredentialStore($owner);

        OwnerMigrations::ensure($app->make(Kernel::class), $database->connection($ownerConnection), $ownerConnection);

        $guard = new NestedTransactionGuard;
        $guard->install($app->make(Dispatcher::class));

        $connections = new IndependentConnections($database, $config);
        $processes = new ChildProcesses($database, $config);

        $app->instance(NestedTransactionGuard::class, $guard);
        $app->instance(IndependentConnections::class, $connections);
        $app->instance(ChildProcesses::class, $processes);

        return new self($database, $ownerConnection, $guard, $connections, $processes);
    }

    /**
     * The connection of the credential store's identity role, which cbox-cms.identity.connection
     * names (PRD 5.16, "Lokale konti"). The harness grants its role the credential store's schema
     * in the test database, as database.sql does, and CheckoutConnections points it at the
     * checkout's or the worker's database with the others.
     */
    public static function identityConnection(Repository $config): string
    {
        $name = $config->get(self::IDENTITY_CONNECTION_KEY);

        if (! is_string($name) || $name === '') {
            throw new AssertionFailedError(sprintf(
                '%s names no database connection. The Postgres harness sets up the credential store of the local accounts for the role of that connection, such as pgsql_identity.',
                self::IDENTITY_CONNECTION_KEY,
            ));
        }

        return $name;
    }

    /**
     * The owner connection's search path lists the credential store's schema after the kernel's,
     * so migrate:fresh, which drops the tables of the schemas in the search path, rebuilds the
     * credential store's tables with the others, and the truncation after each test empties them.
     */
    public static function assertOwnerReachesCredentialStore(ConnectionSettings $owner): void
    {
        $schemas = array_map(trim(...), explode(',', str_replace(['"', "'"], '', $owner->searchPath)));

        if (! in_array(TestDatabaseSetup::IDENTITY_SCHEMA, array_slice($schemas, 1), true)) {
            throw new AssertionFailedError(sprintf(
                'The search path of the owner connection [%s] is "%s". It must list the kernel\'s schema first and %s after it, so migrate:fresh rebuilds the credential store and the harness empties its tables after each test.',
                $owner->name,
                $owner->searchPath,
                TestDatabaseSetup::IDENTITY_SCHEMA,
            ));
        }
    }

    public function finish(): void
    {
        try {
            $this->processes->stopAll();
            $this->connections->closeAll();

            foreach ($this->database->getConnections() as $connection) {
                if ($connection->getName() !== $this->ownerConnection) {
                    $connection->rollBack(0);
                }
            }

            $owner = $this->database->connection($this->ownerConnection);
            $owner->rollBack(0);
            new PartitionSweep($owner)->drop();
            new OwnerTruncation($owner)->truncate();
        } finally {
            try {
                $this->disconnectAll();
            } finally {
                $this->guard->assertClean();
            }
        }
    }

    /**
     * Closes every connection the application opened, so their backends end with the test.
     */
    private function disconnectAll(): void
    {
        foreach (array_keys($this->database->getConnections()) as $name) {
            $this->database->purge($name);
        }
    }
}

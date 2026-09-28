<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use LogicException;
use PDO;
use PDOException;

/**
 * Creates, sets up and drops test databases on a Postgres server, as the owner role connected to
 * the configured database (PRD 4.2: the owner owns the databases; GUARDRAILS 6: raw SQL only in
 * Infrastructure).
 *
 * CREATE DATABASE cannot run inside a transaction, and two processes may provision the same
 * database at once, so provisioning holds a transaction-scoped advisory lock on a connection of
 * its own while a second connection runs the set-up. The lock is named by the caller: the
 * testkit names the checkout's database for it and for the databases of its parallel workers, so
 * the set-ups of one checkout run one at a time. The lock lives in the configured database,
 * which every provisioner connects to, and ends with its transaction however the process ends.
 * The set-up creates the database only when it is missing, and a CREATE DATABASE that loses a
 * race with a creator that takes no lock (SQLSTATE 42P04, duplicate_database) counts as done.
 */
#[Experimental]
final readonly class PostgresTestDatabases
{
    /** SQLSTATE duplicate_database. */
    public const string DUPLICATE_DATABASE = '42P04';

    /** How long a provisioner waits for another one that sets up under the same lock. */
    public const string LOCK_TIMEOUT = '60s';

    /**
     * @param  ConnectionSettings  $server  the owner role on the configured database, which stays
     * @param  string  $lockTimeout  how long provision() waits for the advisory lock, as Postgres's
     *                               lock_timeout reads it, such as `60s`
     */
    public function __construct(
        private ConnectionSettings $server,
        private int $connectTimeoutSeconds,
        private string $lockTimeout = self::LOCK_TIMEOUT,
    ) {}

    /**
     * The key of the advisory lock named $database, which serialises the set-ups that name it: the
     * first 64 bits of a versioned SHA-256 of the name, as a signed bigint.
     */
    public static function lockKey(string $database): int
    {
        // 'J' reads 64 bits big-endian; PHP keeps them as a signed int, the range of a bigint.
        $unpacked = unpack('J', hash('sha256', 'cbox-cms.test-database.v1:'.$database, true));
        $key = is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        if (! is_int($key)) {
            throw new LogicException(sprintf('Could not derive the advisory lock key of %s.', $database));
        }

        return $key;
    }

    /**
     * Whether the connected role has CREATEDB.
     */
    public function canCreateDatabases(): bool
    {
        $statement = $this->connect($this->server)->prepare('select count(*) from pg_roles where rolname = current_user and rolcreatedb');
        $statement->execute();

        return $statement->fetchColumn() === 1;
    }

    /**
     * Creates the database of $setup when it is missing, runs the set-up and records $comment,
     * holding the advisory lock named $lock, or named by the database of $setup when it is null.
     */
    public function provision(TestDatabaseSetup $setup, TestDatabaseComment $comment, ?string $lock = null): void
    {
        $lockName = $lock ?? $setup->database;
        $lock = $this->connect($this->server);
        $lock->beginTransaction();

        try {
            $lock->prepare("select set_config('lock_timeout', ?, true)")->execute([$this->lockTimeout]);
            $lock->exec(sprintf('select pg_advisory_xact_lock(%d)', self::lockKey($lockName)));

            $server = $this->connect($this->server);

            foreach ($setup->onServer() as $statement) {
                self::execute($server, $statement);
            }

            $database = $this->connect($this->server->withDatabase($setup->database));

            foreach ($setup->inDatabase() as $statement) {
                self::execute($database, $statement);
            }

            $database->exec(sprintf(
                'COMMENT ON DATABASE %s IS %s',
                TestDatabaseSetup::identifier($setup->database),
                TestDatabaseSetup::literal($comment->encode()),
            ));

        } finally {
            // The transaction holds only the advisory lock, so ending it either way releases it.
            $lock->rollBack();
        }
    }

    public function exists(string $database): bool
    {
        $statement = $this->connect($this->server)->prepare('select count(*) from pg_database where datname = ?');
        $statement->execute([$database]);

        return $statement->fetchColumn() === 1;
    }

    /**
     * Drops $database when it exists, and says whether it did. Postgres waits up to 5 seconds
     * for the backends of clients that just disconnected, and then for a forced checkpoint of the
     * whole server, which on a server that other checkouts' suites keep busy can take more than a
     * minute. The drop sets no statement timeout of its own.
     */
    public function drop(string $database): bool
    {
        if (! $this->exists($database)) {
            return false;
        }

        $this->connect($this->server)->exec('DROP DATABASE IF EXISTS '.TestDatabaseSetup::identifier($database));

        return true;
    }

    /**
     * Runs one set-up statement as psql would: a `\gexec` statement is a query without parameters,
     * and each value it returns is run in turn. A CREATE DATABASE that finds the database there is done.
     */
    public static function execute(PDO $connection, SetupStatement $statement): void
    {
        if (! $statement->gexec) {
            $connection->exec($statement->sql);

            return;
        }

        $result = $connection->prepare($statement->sql);
        $result->execute();

        foreach ($result->fetchAll(PDO::FETCH_COLUMN) as $generated) {
            if (! is_string($generated)) {
                throw new LogicException(sprintf('The set-up query [%s] generated a value that is not a statement.', $statement->sql));
            }

            try {
                $connection->exec($generated);
            } catch (PDOException $exception) {
                if (($exception->errorInfo[0] ?? null) !== self::DUPLICATE_DATABASE) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * A connection in PDO's default error mode, which throws: pdo_pgsql ignores connect_timeout
     * in the DSN, so ATTR_TIMEOUT bounds the connect.
     */
    private function connect(ConnectionSettings $settings): PDO
    {
        return new PDO($settings->dsn($this->connectTimeoutSeconds), $settings->username, $settings->password, [
            PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds,
        ]);
    }
}

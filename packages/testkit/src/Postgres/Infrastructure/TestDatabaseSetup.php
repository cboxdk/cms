<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The statements that set up a Cbox CMS database, the same as docker/postgres/sql/database.sql
 * with its psql variables substituted, in its order: the database owned by the owner role, no
 * privileges for PUBLIC and CONNECT for the app role; then, in the new database, the schema owned
 * by the owner role with USAGE for the app role, no CREATE on public for PUBLIC, and the owner's
 * default privileges, which give the app role DML on every table and sequence a migration creates.
 * Owning the database gives the owner role CREATE on it, which is all CREATE EXTENSION of a trusted
 * extension such as ltree needs, so the core's migrations create it without a superuser, in each
 * checkout's and each parallel worker's database alike.
 *
 * The testkit cannot read docker/ when it is installed on its own, so it keeps a copy;
 * tests/Feature/Tooling/TestDatabaseSetupTest.php holds the copy equal to the file, statement for
 * statement. Like the file it is idempotent and grants nothing on existing tables, so running it
 * again never undoes a revoke of a migration.
 */
#[Experimental]
final readonly class TestDatabaseSetup
{
    public function __construct(
        public string $database,
        public string $ownerRole,
        public string $appRole,
        public string $schema,
    ) {
        foreach (['database' => $database, 'owner role' => $ownerRole, 'app role' => $appRole, 'schema' => $schema] as $what => $name) {
            if ($name === '' || str_contains($name, "\0")) {
                throw new InvalidArgumentException(sprintf('The %s of the test database set-up has no valid name.', $what));
            }
        }
    }

    /**
     * What database.sql runs before `\connect :"db"`, on the server's other database.
     *
     * @return list<SetupStatement>
     */
    public function onServer(): array
    {
        $db = self::identifier($this->database);

        return [
            new SetupStatement(sprintf(
                "SELECT format('CREATE DATABASE %%I OWNER %%I', %s, %s) WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = %s)",
                self::literal($this->database),
                self::literal($this->ownerRole),
                self::literal($this->database),
            ), gexec: true),
            new SetupStatement(sprintf('ALTER DATABASE %s OWNER TO %s', $db, self::identifier($this->ownerRole))),
            new SetupStatement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $db)),
            new SetupStatement(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $db, self::identifier($this->appRole))),
        ];
    }

    /**
     * What database.sql runs after `\connect :"db"`, in the new database.
     *
     * @return list<SetupStatement>
     */
    public function inDatabase(): array
    {
        $schema = self::identifier($this->schema);
        $owner = self::identifier($this->ownerRole);
        $app = self::identifier($this->appRole);

        return [
            new SetupStatement(sprintf('CREATE SCHEMA IF NOT EXISTS %s AUTHORIZATION %s', $schema, $owner)),
            new SetupStatement(sprintf('ALTER SCHEMA %s OWNER TO %s', $schema, $owner)),
            new SetupStatement(sprintf('REVOKE ALL ON SCHEMA %s FROM PUBLIC', $schema)),
            new SetupStatement(sprintf('GRANT USAGE ON SCHEMA %s TO %s', $schema, $app)),
            new SetupStatement('REVOKE CREATE ON SCHEMA public FROM PUBLIC'),
            new SetupStatement(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %s', $owner, $schema, $app)),
            new SetupStatement(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT USAGE, SELECT ON SEQUENCES TO %s', $owner, $schema, $app)),
        ];
    }

    /**
     * A quoted identifier, as psql substitutes `:"name"`.
     */
    public static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * A quoted literal, as psql substitutes `:'name'` for a value without backslashes.
     */
    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}

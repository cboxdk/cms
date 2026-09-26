<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Adapter;

use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Cbox\Cms\Tooling\TestDatabase\Domain\ListedDatabase;
use PDO;
use UnexpectedValueException;

/**
 * The databases on the test server and their comments, and the drop that `composer
 * test-db:prune` runs, as the owner role connected to the configured database.
 *
 * The drop is DROP DATABASE ... WITH (FORCE), which terminates the sessions still connected to
 * the database first, such as a test process of a removed worktree that was killed and left an
 * app-role connection behind. Terminating another role's session needs membership of
 * pg_signal_backend, which docker/postgres/sql/roles.sql grants the owner role.
 */
final readonly class PostgresTestDatabaseCatalog
{
    public function __construct(
        private ConnectionSettings $server,
        private int $connectTimeoutSeconds = 2,
    ) {}

    /**
     * Every database that is not a template, by name, with its comment.
     *
     * @return list<ListedDatabase>
     */
    public function databases(): array
    {
        $statement = $this->connect()->query(
            "select datname, shobj_description(oid, 'pg_database') from pg_database where not datistemplate order by datname collate \"C\""
        );
        $databases = [];

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = is_array($row) ? ($row[0] ?? null) : null;
            $comment = is_array($row) ? ($row[1] ?? null) : null;

            if (! is_string($name) || ($comment !== null && ! is_string($comment))) {
                throw new UnexpectedValueException('pg_database returned a row that is not a name and a comment.');
            }

            $databases[] = new ListedDatabase($name, $comment);
        }

        return $databases;
    }

    /**
     * Drops $database, and the sessions still connected to it, when it exists.
     */
    public function drop(string $database): void
    {
        $this->connect()->exec('DROP DATABASE IF EXISTS '.TestDatabaseSetup::identifier($database).' WITH (FORCE)');
    }

    private function connect(): PDO
    {
        return new PDO($this->server->dsn($this->connectTimeoutSeconds), $this->server->username, $this->server->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Opens connections that share nothing with each other or with the default connection.
 *
 * Each one is a copy of a configured connection under a fresh name, so it has its own PDO and
 * its own Postgres backend: a transaction on one is invisible to the others until it commits,
 * and a lock one holds makes the others wait (GUARDRAILS 9, real Postgres with separate
 * connections). They fire their events on the application's dispatcher, so the nested
 * transaction guard covers them. The harness closes them after each test, rolling back any
 * transaction a test left open.
 */
#[Experimental]
final class IndependentConnections
{
    /** @var array<string, PostgresConnection> */
    private array $opened = [];

    private int $sequence = 0;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    /**
     * Opens $count connections as copies of $from, the default connection when null.
     *
     * @return list<PostgresConnection>
     */
    public function open(int $count, ?string $from = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Open at least one connection.');
        }

        $from ??= $this->database->getDefaultConnection();
        $settings = $this->config->get('database.connections.'.$from);

        if (! is_array($settings)) {
            throw new LogicException(sprintf('The database connection [%s] is not configured.', $from));
        }

        $connections = [];

        for ($i = 0; $i < $count; $i++) {
            $name = sprintf('%s__independent_%d', $from, ++$this->sequence);
            $this->config->set('database.connections.'.$name, $settings);

            $connection = $this->database->connection($name);

            if (! $connection instanceof PostgresConnection) {
                $this->database->purge($name);
                $this->config->set('database.connections.'.$name);

                throw new LogicException(sprintf('The database connection [%s] is not a Postgres connection.', $from));
            }

            $this->opened[$name] = $connection;

            // Connect now, so each connection has its own backend before the test uses it.
            $connection->getPdo();
            $connections[] = $connection;
        }

        return $connections;
    }

    /**
     * Rolls back what each connection left open, disconnects it and forgets its configuration.
     */
    public function closeAll(): void
    {
        foreach ($this->opened as $name => $connection) {
            try {
                $connection->rollBack(0);
            } catch (Throwable) {
                // The connection may already be broken; disconnecting below still frees the backend.
            }

            $this->database->purge($name);
            $this->config->set('database.connections.'.$name);
        }

        $this->opened = [];
    }
}

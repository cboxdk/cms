<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * The settings of one Postgres connection, read from `database.connections.<name>`.
 *
 * The harness needs them outside Laravel's connection factory: the service check opens a raw
 * PDO connection with a short connect timeout, and a child process builds its own connection
 * from them. Only the keys a pgsql connection needs are kept.
 */
#[Experimental]
final readonly class ConnectionSettings
{
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $searchPath,
    ) {}

    public static function of(string $connection, Repository $config): self
    {
        $settings = $config->get('database.connections.'.$connection);

        if (! is_array($settings)) {
            throw new LogicException(sprintf('The database connection [%s] is not configured.', $connection));
        }

        if (($settings['driver'] ?? null) !== 'pgsql') {
            throw new LogicException(sprintf('The database connection [%s] is not a pgsql connection.', $connection));
        }

        $port = $settings['port'] ?? 5432;

        return new self(
            name: $connection,
            host: self::string($settings, 'host', $connection),
            port: is_numeric($port) ? (int) $port : throw new LogicException(sprintf('The port of [%s] is not a number.', $connection)),
            database: self::string($settings, 'database', $connection),
            username: self::string($settings, 'username', $connection),
            password: is_string($settings['password'] ?? null) ? $settings['password'] : '',
            searchPath: is_string($settings['search_path'] ?? null) ? $settings['search_path'] : 'public',
        );
    }

    /**
     * The PDO data source name, with a connect timeout so an unreachable server fails fast.
     */
    public function dsn(int $connectTimeoutSeconds): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            $this->host,
            $this->port,
            $this->database,
            $connectTimeoutSeconds,
        );
    }

    /**
     * The connection configuration Laravel's connection factory takes.
     *
     * @return array<string, string|int>
     */
    public function toConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => $this->searchPath,
            'sslmode' => 'prefer',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    private static function string(array $settings, string $key, string $connection): string
    {
        $value = $settings[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new LogicException(sprintf('The database connection [%s] has no %s.', $connection, $key));
        }

        return $value;
    }
}

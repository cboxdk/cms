<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
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
     * The same connection to another database on the same server.
     */
    public function withDatabase(string $database): self
    {
        return new self($this->name, $this->host, $this->port, $database, $this->username, $this->password, $this->searchPath);
    }

    /**
     * The settings as the payloads of the harness's child processes carry them.
     *
     * @return array{name: string, host: string, port: int, database: string, username: string, password: string, search_path: string}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'search_path' => $this->searchPath,
        ];
    }

    /**
     * Reads what toPayload() wrote.
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type
     */
    public static function fromPayload(mixed $values): self
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException('The payload has no connection.');
        }

        $port = $values['port'] ?? null;

        return new self(
            name: self::payloadString($values, 'name'),
            host: self::payloadString($values, 'host'),
            port: is_int($port) ? $port : throw new InvalidArgumentException('The payload has no port.'),
            database: self::payloadString($values, 'database'),
            username: self::payloadString($values, 'username'),
            password: self::payloadString($values, 'password'),
            searchPath: self::payloadString($values, 'search_path'),
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
     * @param  array<array-key, mixed>  $values
     */
    private static function payloadString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The payload has no %s.', $key));
        }

        return $value;
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

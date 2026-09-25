<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;
use JsonException;

/**
 * What the parent test sends a child process on standard input: the connection to open and
 * either a serialised closure or the path of a PHP script that returns a closure.
 *
 * The password travels on standard input, never on the command line, where `ps` would show it.
 */
#[Internal]
final readonly class ChildPayload
{
    public function __construct(
        public ConnectionSettings $connection,
        public ?string $closure = null,
        public ?string $script = null,
    ) {
        if (($closure === null) === ($script === null)) {
            throw new InvalidArgumentException('A child process runs either a closure or a script.');
        }
    }

    public function encode(): string
    {
        return json_encode([
            'connection' => [
                'name' => $this->connection->name,
                'host' => $this->connection->host,
                'port' => $this->connection->port,
                'database' => $this->connection->database,
                'username' => $this->connection->username,
                'password' => $this->connection->password,
                'search_path' => $this->connection->searchPath,
            ],
            'closure' => $this->closure === null ? null : base64_encode($this->closure),
            'script' => $this->script,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidArgumentException when the payload is not what encode() writes
     */
    public static function decode(string $json): self
    {
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The child payload is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload) || ! is_array($payload['connection'] ?? null)) {
            throw new InvalidArgumentException('The child payload has no connection.');
        }

        $connection = $payload['connection'];
        $closure = $payload['closure'] ?? null;
        $script = $payload['script'] ?? null;

        if ($closure !== null) {
            $closure = is_string($closure) ? base64_decode($closure, true) : false;

            if ($closure === false) {
                throw new InvalidArgumentException('The child payload has an invalid closure.');
            }
        }

        if ($script !== null && ! is_string($script)) {
            throw new InvalidArgumentException('The child payload has an invalid script.');
        }

        return new self(
            connection: new ConnectionSettings(
                name: self::string($connection, 'name'),
                host: self::string($connection, 'host'),
                port: is_int($connection['port'] ?? null) ? $connection['port'] : throw new InvalidArgumentException('The child payload has no port.'),
                database: self::string($connection, 'database'),
                username: self::string($connection, 'username'),
                password: self::string($connection, 'password'),
                searchPath: self::string($connection, 'search_path'),
            ),
            closure: $closure,
            script: $script,
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The child payload has no %s.', $key));
        }

        return $value;
    }
}

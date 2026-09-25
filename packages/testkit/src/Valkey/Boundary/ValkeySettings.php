<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;
use LogicException;

/**
 * The settings of one Valkey connection, read from `database.redis.<name>`.
 *
 * The harness needs them outside Laravel's Redis manager: the service check and the key clean-up
 * open a raw phpredis client with a short timeout, and a child process opens its own client from
 * them. A `url` is parsed the way Laravel parses it, and its values win over the separate keys.
 */
#[Experimental]
final readonly class ValkeySettings
{
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public int $database,
        public ?string $username = null,
        public ?string $password = null,
    ) {
        if ($database < 0) {
            throw new LogicException(sprintf('The Redis connection [%s] has a negative database index.', $name));
        }
    }

    public static function of(string $connection, Repository $config): self
    {
        $settings = $config->get('database.redis.'.$connection);

        if (! is_array($settings)) {
            throw new LogicException(sprintf('The Redis connection [%s] is not configured.', $connection));
        }

        $settings = new ConfigurationUrlParser()->parseConfiguration(array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY));

        $host = $settings['host'] ?? null;
        $port = $settings['port'] ?? 6379;
        $database = $settings['database'] ?? 0;

        if (! is_string($host) || $host === '') {
            throw new LogicException(sprintf('The Redis connection [%s] has no host.', $connection));
        }

        return new self(
            name: $connection,
            host: $host,
            port: is_numeric($port) ? (int) $port : throw new LogicException(sprintf('The port of the Redis connection [%s] is not a number.', $connection)),
            database: is_numeric($database) ? (int) $database : throw new LogicException(sprintf('The database of the Redis connection [%s] is not a number.', $connection)),
            username: self::optional($settings, 'username'),
            password: self::optional($settings, 'password'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    private static function optional(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

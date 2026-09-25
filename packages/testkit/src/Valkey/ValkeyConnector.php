<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Valkey\Boundary\ValkeySettings;
use Redis;
use RedisException;

/**
 * Opens a raw phpredis client on Valkey, outside Laravel's Redis manager.
 *
 * The connect and read timeouts are short, so a stopped service fails in seconds, and the client
 * selects the settings' database index. With a prefix, phpredis puts it in front of every key the
 * client sends; without one the client sees the key names as they are stored.
 */
#[Experimental]
final class ValkeyConnector
{
    public const float TIMEOUT_SECONDS = 2.0;

    /**
     * @throws RedisException when Valkey is not reachable, refuses the credentials or the database index
     */
    public static function connect(ValkeySettings $settings, string $prefix = '', float $timeoutSeconds = self::TIMEOUT_SECONDS): Redis
    {
        $client = new Redis;

        if (! $client->connect($settings->host, $settings->port, $timeoutSeconds, null, 0, $timeoutSeconds)) {
            throw new RedisException(sprintf('Could not connect to %s:%d.', $settings->host, $settings->port));
        }

        if ($settings->password !== null) {
            $credentials = $settings->username === null ? $settings->password : [$settings->username, $settings->password];

            if ($client->auth($credentials) !== true) {
                throw new RedisException(sprintf('Valkey at %s:%d refused the credentials.', $settings->host, $settings->port));
            }
        }

        if ($client->select($settings->database) !== true) {
            throw new RedisException(sprintf('Valkey at %s:%d refused the database index %d.', $settings->host, $settings->port, $settings->database));
        }

        if ($prefix !== '') {
            $client->setOption(Redis::OPT_PREFIX, $prefix);
        }

        return $client;
    }
}

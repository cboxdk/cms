<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use LogicException;
use Override;
use Redis;
use UnexpectedValueException;

/**
 * The login throttle in Valkey (PRD 5.16), on a phpredis connection to one Valkey primary, so every
 * process of the installation counts against the same limits.
 *
 * A key's count is `cms:login_throttle:<scope>:<SHA-256>`, below the connection's own prefix: an
 * integer that expires its scope's window after the first attempt it counted. Counting and taking
 * back each run one Lua script over both keys, so two attempts at the same time are counted one
 * after the other and the first ones are the ones let through.
 */
#[Internal]
final readonly class ValkeyLoginThrottle implements LoginThrottle
{
    public const string KEY = 'cms:login_throttle:';

    /**
     * KEYS: the identifier's count, the IP address's count.
     * ARGV: the identifier's attempts and window (ms), the IP address's attempts and window (ms).
     * Returns 0 when both counts are within their limits, 1 when the identifier's is above, 2 when
     * only the IP address's is.
     */
    public const string HIT = <<<'LUA'
        local over = 0
        for i = 1, 2 do
          local count = redis.call('INCR', KEYS[i])
          if count == 1 then
            redis.call('PEXPIRE', KEYS[i], tonumber(ARGV[i * 2]))
          end
          if over == 0 and count > tonumber(ARGV[i * 2 - 1]) then
            over = i
          end
        end
        return over
        LUA;

    /**
     * KEYS: the identifier's count, the IP address's count.
     * Clears the first and takes one off the second, which it deletes at zero, keeping its window.
     */
    public const string SUCCEEDED = <<<'LUA'
        redis.call('DEL', KEYS[1])
        if redis.call('EXISTS', KEYS[2]) == 1 and redis.call('DECR', KEYS[2]) <= 0 then
          redis.call('DEL', KEYS[2])
        end
        return 1
        LUA;

    public function __construct(
        private Factory $redis,
        private LoginThrottleSettings $settings,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function hit(LoginThrottleKeys $keys): ?ThrottleScope
    {
        $over = $this->run(self::HIT, $keys, [
            (string) $this->settings->identifier->attempts,
            (string) ($this->settings->identifier->windowSeconds * 1000),
            (string) $this->settings->ip->attempts,
            (string) ($this->settings->ip->windowSeconds * 1000),
        ]);

        return match ($over) {
            0 => null,
            1 => ThrottleScope::Identifier,
            2 => ThrottleScope::Ip,
            default => throw new UnexpectedValueException('The login throttle\'s script gave an answer it does not give.'),
        };
    }

    #[Override]
    public function succeeded(LoginThrottleKeys $keys): void
    {
        $this->run(self::SUCCEEDED, $keys, []);
    }

    /**
     * The name of a key's count, below the connection's prefix.
     */
    public static function key(ThrottleScope $scope, LoginThrottleKeys $keys): string
    {
        return self::KEY.$scope->value.':'.$keys->key($scope);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(string $script, LoginThrottleKeys $keys, array $arguments): int
    {
        $connection = $this->client();
        $client = $connection->client();

        if ($client instanceof Redis) {
            $client->clearLastError();
        }

        $result = $connection->command('eval', [$script, [self::key(ThrottleScope::Identifier, $keys), self::key(ThrottleScope::Ip, $keys), ...$arguments], 2]);

        if (! is_int($result)) {
            throw new UnexpectedValueException(sprintf(
                'The login throttle\'s script failed: %s',
                ($client instanceof Redis ? $client->getLastError() : null) ?? get_debug_type($result),
            ));
        }

        return $result;
    }

    private function client(): PhpRedisConnection
    {
        $connection = $this->redis->connection($this->connection);

        if (! $connection instanceof PhpRedisConnection || $connection instanceof PhpRedisClusterConnection) {
            throw new LogicException(sprintf(
                'The login throttle needs a phpredis connection to one Valkey primary; the Redis connection [%s] is a %s.',
                $this->connection ?? 'default',
                get_debug_type($connection),
            ));
        }

        return $connection;
    }
}

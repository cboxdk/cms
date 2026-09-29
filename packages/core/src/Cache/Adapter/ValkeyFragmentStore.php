<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Cache\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Cache\FragmentWriteOutcome;
use Cbox\Cms\Contracts\Cache\InvalidCacheValue;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use LogicException;
use Override;
use Redis;
use UnexpectedValueException;

/**
 * The fragment store and cache index in Valkey (PRD 8.12 point 1, 9.3), on a phpredis connection
 * to one Valkey primary.
 *
 * Keys, below the connection's own prefix:
 *
 * - `cms:fragment:<fragment key>`, a hash: the fragment key, body, build position, validUntil in
 *   unix microseconds, the dependency keys as JSON, the names of their index sets as JSON, and a
 *   field `d:<dependency key>` per dependency, so a stale index entry is told from a live one.
 * - `cms:fragments_of:<dependency key>`, a set: the names of the fragment hashes that depend on
 *   the key, the reverse index.
 * - `cms:purged:<dependency key>`, a hash: the fence's position and its end in unix microseconds.
 *
 * Every key expires with what it is for: a fragment at its validUntil, an index set no sooner
 * than the last of its fragments, and a fence at its fenceUntil, each as a TTL counted from the
 * Clock's time. The store also compares the Clock's time with the stored instants, so a fragment
 * or fence is gone at the Clock's instant even when the TTL has not run out.
 *
 * write() and purge() each run one Lua script, so the fence check, the fragment and the index
 * change together or not at all, and no purge can come between the check and the write. The
 * scripts touch the fragment hashes the index names, keys they are not given, so the store needs
 * one Valkey primary, not a cluster. Positions are compared as decimal strings, by length and
 * then by bytes, because Lua's numbers lose precision above 2^53 and an xid8 goes to 2^64 - 1.
 */
#[Experimental]
final readonly class ValkeyFragmentStore implements FragmentStore
{
    public const string FRAGMENT = 'cms:fragment:';

    public const string INDEX = 'cms:fragments_of:';

    public const string FENCE = 'cms:purged:';

    private const string BELOW = <<<'LUA'
        local function below(a, b)
          if #a ~= #b then return #a < #b end
          return a < b
        end

        LUA;

    /**
     * KEYS: the fragment hash, then the index set of each dependency, then the fence of each.
     * ARGV: now (µs), fragment key, body, build position, validUntil (µs), TTL (ms), the
     * dependencies as JSON, the index sets as JSON, then each dependency key.
     * Returns {1} when stored, or {0, dependency key, fence position} when fenced.
     */
    public const string WRITE = self::BELOW.<<<'LUA'
        local n = (#KEYS - 1) / 2
        local now = tonumber(ARGV[1])
        local built = ARGV[4]
        local ttl = tonumber(ARGV[6])
        for i = 1, n do
          local fence = redis.call('HMGET', KEYS[1 + n + i], 'position', 'until')
          if fence[1] and tonumber(fence[2]) > now and not below(fence[1], built) then
            return {0, ARGV[8 + i], fence[1]}
          end
        end
        local old = redis.call('HGET', KEYS[1], 'sets')
        if old then
          for _, set in ipairs(cjson.decode(old)) do
            redis.call('SREM', set, KEYS[1])
          end
        end
        redis.call('DEL', KEYS[1])
        local fields = {'key', ARGV[2], 'body', ARGV[3], 'built_at', built, 'valid_until', ARGV[5], 'deps', ARGV[7], 'sets', ARGV[8]}
        for i = 1, n do
          table.insert(fields, 'd:' .. ARGV[8 + i])
          table.insert(fields, '1')
        end
        redis.call('HSET', KEYS[1], unpack(fields))
        redis.call('PEXPIRE', KEYS[1], ttl)
        for i = 1, n do
          local set = KEYS[1 + i]
          redis.call('SADD', set, KEYS[1])
          if redis.call('PTTL', set) < ttl then
            redis.call('PEXPIRE', set, ttl)
          end
        end
        return {1}
        LUA;

    /**
     * KEYS: the fragment hash.
     * Returns its key, body, build position, validUntil (µs) and dependencies, or an empty list.
     * A script, so the values come back as stored, whatever serializer the connection sets.
     */
    public const string READ = <<<'LUA'
        local fragment = redis.call('HMGET', KEYS[1], 'key', 'body', 'built_at', 'valid_until', 'deps')
        if not fragment[1] then return {} end
        return fragment
        LUA;

    /**
     * KEYS: the index set of the dependency.
     * ARGV: now (µs), the dependency key.
     * Returns the fragment keys of the live fragments that depend on it.
     */
    public const string FRAGMENTS_OF = <<<'LUA'
        local now = tonumber(ARGV[1])
        local keys = {}
        for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
          local fragment = redis.call('HMGET', member, 'key', 'valid_until', 'd:' .. ARGV[2])
          if fragment[1] and fragment[3] and tonumber(fragment[2]) > now then
            table.insert(keys, fragment[1])
          end
        end
        return keys
        LUA;

    /**
     * KEYS: the index set of the dependency, then its fence.
     * ARGV: now (µs), the purge position, fenceUntil (µs), the dependency key.
     * Returns the fragment keys of the live fragments it removed.
     */
    public const string PURGE = self::BELOW.<<<'LUA'
        local now = tonumber(ARGV[1])
        local position = ARGV[2]
        local ends = ARGV[3]
        local fence = redis.call('HMGET', KEYS[2], 'position', 'until')
        if fence[1] and tonumber(fence[2]) > now then
          if below(position, fence[1]) then position = fence[1] end
          if below(ends, fence[2]) then ends = fence[2] end
        end
        redis.call('DEL', KEYS[2])
        redis.call('HSET', KEYS[2], 'position', position, 'until', ends)
        redis.call('PEXPIRE', KEYS[2], math.max(1, math.ceil((tonumber(ends) - now) / 1000)))
        local removed = {}
        for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
          local fragment = redis.call('HMGET', member, 'key', 'valid_until', 'sets', 'd:' .. ARGV[4])
          if fragment[1] and fragment[4] then
            if tonumber(fragment[2]) > now then
              table.insert(removed, fragment[1])
            end
            for _, set in ipairs(cjson.decode(fragment[3])) do
              if set ~= KEYS[1] then
                redis.call('SREM', set, member)
              end
            end
            redis.call('DEL', member)
          end
        end
        redis.call('DEL', KEYS[1])
        return removed
        LUA;

    public function __construct(
        private Factory $redis,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function write(Fragment $fragment): FragmentWriteOutcome
    {
        $now = $this->clock->now();

        if ($fragment->validUntil <= $now) {
            throw InvalidCacheValue::fragmentExpired($fragment->key, $fragment->validUntil, $now);
        }

        $client = $this->client();
        $prefix = $this->prefix($client);
        $dependencies = array_map(static fn (DependencyKey $key): string => $key->toString(), $fragment->dependencies);
        $sets = array_map(static fn (string $key): string => $prefix.self::INDEX.$key, $dependencies);
        $keys = [
            self::FRAGMENT.$fragment->key->value,
            ...array_map(static fn (string $key): string => self::INDEX.$key, $dependencies),
            ...array_map(static fn (string $key): string => self::FENCE.$key, $dependencies),
        ];

        $result = $this->run($client, self::WRITE, $keys, [
            $this->microseconds($now),
            $fragment->key->value,
            $fragment->body,
            $fragment->builtAt->value,
            $this->microseconds($fragment->validUntil),
            (string) $this->ttlMilliseconds($now, $fragment->validUntil),
            $this->json($dependencies),
            $this->json($sets),
            ...$dependencies,
        ]);

        if ($result === [1]) {
            return new FragmentStored($fragment);
        }

        if (count($result) === 3 && $result[0] === 0 && is_string($result[1]) && is_string($result[2])) {
            return new FragmentFenced(
                $fragment->key,
                DependencyKey::fromString($result[1]),
                new CommitPosition($result[2]),
                $fragment->builtAt,
            );
        }

        throw $this->unexpected('write', $result);
    }

    #[Override]
    public function read(FragmentKey $key): ?Fragment
    {
        $now = $this->clock->now();
        $values = $this->run($this->client(), self::READ, [self::FRAGMENT.$key->value], []);

        if ($values === []) {
            return null;
        }

        [$stored, $body, $builtAt, $validUntil, $dependencies] = count($values) === 5 ? $values : throw $this->unexpected('read', $values);
        $dependencies = is_string($dependencies) ? json_decode($dependencies, true) : null;

        if (! is_string($stored) || ! is_string($body) || ! is_string($builtAt) || ! is_string($validUntil) || ! is_array($dependencies) || ! array_is_list($dependencies)) {
            throw $this->unexpected('read', $values);
        }

        $fragment = new Fragment(
            new FragmentKey($stored),
            $body,
            array_map(
                fn (mixed $dependency): DependencyKey => is_string($dependency) ? DependencyKey::fromString($dependency) : throw $this->unexpected('read', $values),
                $dependencies,
            ),
            new CommitPosition($builtAt),
            $this->instant($validUntil),
        );

        return $fragment->validUntil > $now ? $fragment : null;
    }

    #[Override]
    public function fragmentsOf(DependencyKey $key): array
    {
        $result = $this->run($this->client(), self::FRAGMENTS_OF, [self::INDEX.$key->toString()], [
            $this->microseconds($this->clock->now()),
            $key->toString(),
        ]);

        return $this->fragmentKeys('fragmentsOf', $result);
    }

    #[Override]
    public function purge(FragmentPurge $purge): array
    {
        $now = $this->clock->now();

        if ($purge->fenceUntil <= $now) {
            throw InvalidCacheValue::fenceEnded($purge->key, $purge->fenceUntil, $now);
        }

        $key = $purge->key->toString();
        $result = $this->run($this->client(), self::PURGE, [self::INDEX.$key, self::FENCE.$key], [
            $this->microseconds($now),
            $purge->position->value,
            $this->microseconds($purge->fenceUntil),
            $key,
        ]);

        return $this->fragmentKeys('purge', $result);
    }

    private function client(): PhpRedisConnection
    {
        $connection = $this->redis->connection($this->connection);

        if (! $connection instanceof PhpRedisConnection || $connection instanceof PhpRedisClusterConnection) {
            throw new LogicException(sprintf(
                'The fragment store needs a phpredis connection to one Valkey primary; the Redis connection [%s] is a %s.',
                $this->connection ?? 'default',
                get_debug_type($connection),
            ));
        }

        return $connection;
    }

    /**
     * The prefix phpredis puts in front of every key, which the scripts store in the index.
     */
    private function prefix(PhpRedisConnection $connection): string
    {
        $client = $connection->client();

        return $client instanceof Redis ? $client->_prefix('') : '';
    }

    /**
     * Runs a script in one EVAL through the connection, which reports the command to Laravel's
     * Redis events; phpredis puts the prefix in front of each of $keys.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     * @return list<mixed>
     */
    private function run(PhpRedisConnection $connection, string $script, array $keys, array $arguments): array
    {
        $client = $connection->client();

        if ($client instanceof Redis) {
            $client->clearLastError();
        }

        $result = $connection->command('eval', [$script, [...$keys, ...$arguments], count($keys)]);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new UnexpectedValueException(sprintf(
                'The fragment store\'s script failed: %s',
                ($client instanceof Redis ? $client->getLastError() : null) ?? get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $result
     * @return list<FragmentKey>
     */
    private function fragmentKeys(string $operation, array $result): array
    {
        $keys = [];

        foreach ($result as $key) {
            $keys[] = is_string($key) ? $key : throw $this->unexpected($operation, $result);
        }

        sort($keys, SORT_STRING);

        return array_map(static fn (string $key): FragmentKey => new FragmentKey($key), $keys);
    }

    private function microseconds(DateTimeImmutable $time): string
    {
        return $time->format('U').$time->format('u');
    }

    private function instant(string $microseconds): DateTimeImmutable
    {
        if (preg_match('/\A[1-9][0-9]{6,}\z/', $microseconds) !== 1) {
            throw new UnexpectedValueException(sprintf('The fragment store holds a validUntil of "%s".', $microseconds));
        }

        $instant = DateTimeImmutable::createFromFormat('U.u', substr($microseconds, 0, -6).'.'.substr($microseconds, -6), new DateTimeZone('UTC'));

        return $instant instanceof DateTimeImmutable
            ? $instant->setTimezone(new DateTimeZone('UTC'))
            : throw new UnexpectedValueException(sprintf('The fragment store holds a validUntil of "%s".', $microseconds));
    }

    /**
     * The milliseconds from $now to $until, rounded up, so the copy never expires before $until.
     */
    private function ttlMilliseconds(DateTimeImmutable $now, DateTimeImmutable $until): int
    {
        return max(1, intdiv((int) $this->microseconds($until) - (int) $this->microseconds($now) + 999, 1000));
    }

    /**
     * @param  list<string>  $values
     */
    private function json(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function unexpected(string $operation, mixed $result): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf(
            'The fragment store\'s %s got an answer it does not know: %s',
            $operation,
            json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: get_debug_type($result),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use LogicException;
use Override;
use Redis;
use Throwable;
use UnexpectedValueException;

/**
 * The session store in Valkey (PRD 5.16), on a phpredis connection to one Valkey primary.
 *
 * Keys, below the connection's own prefix:
 *
 * - `cms:session:<key>`, a hash per session under the SHA-256 of its id: the actor, its class, the
 *   connection, the login method, the factors the login gave, the IdP session id or an empty
 *   string, the credential
 *   generation, the issued and last-seen times in unix microseconds, and the names of the sets it
 *   is in as JSON;
 * - `cms:sessions_of_actor:<actor id>`, a set of the session hashes of an actor;
 * - `cms:sessions_of_idp:<connection>:<SHA-256 of the IdP session id>`, a set of the session
 *   hashes from one IdP session.
 *
 * A session hash expires at the end it was put or last touched with, as a TTL counted from the
 * Clock's time, and a set no sooner than the last of its sessions. Every change runs one Lua
 * script, so a session and the sets that name it change together: ending a set deletes each
 * session it names and takes it out of its other set, and a touch of a session that was ended
 * meanwhile changes nothing. The scripts touch the session hashes a set names, keys they are not
 * given, so the store needs one Valkey primary, not a cluster.
 */
#[Internal]
final readonly class ValkeySessionStore implements SessionStore
{
    public const string SESSION = 'cms:session:';

    public const string OF_ACTOR = 'cms:sessions_of_actor:';

    public const string OF_IDP_SESSION = 'cms:sessions_of_idp:';

    /**
     * KEYS: the session hash, then each set it goes in.
     * ARGV: TTL (ms), the session hash's full name, the sets' full names as JSON, then the fields
     * and their values.
     */
    public const string PUT = <<<'LUA'
        local ttl = tonumber(ARGV[1])
        redis.call('DEL', KEYS[1])
        local fields = {'sets', ARGV[3]}
        for i = 4, #ARGV do
          table.insert(fields, ARGV[i])
        end
        redis.call('HSET', KEYS[1], unpack(fields))
        redis.call('PEXPIRE', KEYS[1], ttl)
        for i = 2, #KEYS do
          redis.call('SADD', KEYS[i], ARGV[2])
          if redis.call('PTTL', KEYS[i]) < ttl then
            redis.call('PEXPIRE', KEYS[i], ttl)
          end
        end
        return 1
        LUA;

    /**
     * KEYS: the session hash.
     * Returns its fields in the order of FIELDS, or an empty list. A script, so the values come
     * back as stored, whatever serializer the connection sets.
     */
    public const string FIND = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
        return redis.call('HMGET', KEYS[1], 'actor', 'class', 'connection', 'method', 'factors', 'idp_session', 'generation', 'issued', 'seen')
        LUA;

    /**
     * KEYS: the session hash.
     * ARGV: TTL (ms), the last-seen time (µs).
     * Returns 1 when the session was there and is renewed, 0 when it is gone.
     */
    public const string TOUCH = <<<'LUA'
        local sets = redis.call('HGET', KEYS[1], 'sets')
        if not sets then return 0 end
        local ttl = tonumber(ARGV[1])
        redis.call('HSET', KEYS[1], 'seen', ARGV[2])
        redis.call('PEXPIRE', KEYS[1], ttl)
        for _, set in ipairs(cjson.decode(sets)) do
          if redis.call('PTTL', set) < ttl then
            redis.call('PEXPIRE', set, ttl)
          end
        end
        return 1
        LUA;

    /**
     * KEYS: the session hash.
     * ARGV: the session hash's full name.
     * Returns 1 when it was there, 0 when not.
     */
    public const string END = <<<'LUA'
        local sets = redis.call('HGET', KEYS[1], 'sets')
        if not sets then return 0 end
        for _, set in ipairs(cjson.decode(sets)) do
          redis.call('SREM', set, ARGV[1])
        end
        redis.call('DEL', KEYS[1])
        return 1
        LUA;

    /**
     * KEYS: a set of sessions.
     * Returns how many sessions it named that were there; each is deleted and taken out of its
     * other sets, and the set is deleted.
     */
    public const string END_SET = <<<'LUA'
        local ended = 0
        for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
          local sets = redis.call('HGET', member, 'sets')
          if sets then
            for _, set in ipairs(cjson.decode(sets)) do
              if set ~= KEYS[1] then
                redis.call('SREM', set, member)
              end
            end
            redis.call('DEL', member)
            ended = ended + 1
          end
        end
        redis.call('DEL', KEYS[1])
        return ended
        LUA;

    public function __construct(
        private Factory $redis,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function put(StoredSession $session, DateTimeImmutable $expiresAt): void
    {
        $client = $this->client();
        $prefix = $this->prefix($client);
        $sets = $this->sets($session);
        $ttl = $this->ttlMilliseconds($expiresAt);

        $this->run($client, self::PUT, [self::SESSION.$session->key->value, ...$sets], [
            (string) $ttl,
            $prefix.self::SESSION.$session->key->value,
            json_encode(array_map(static fn (string $set): string => $prefix.$set, $sets), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'actor', $session->actor->toString(),
            'class', $session->actorClass->value,
            'connection', $session->connection->value,
            'method', $session->method->value,
            'factors', $session->factors->value,
            'idp_session', $session->idpSession->value ?? '',
            'generation', (string) $session->generation->value,
            'issued', $this->microseconds($session->issuedAt),
            'seen', $this->microseconds($session->lastSeenAt),
        ]);
    }

    #[Override]
    public function find(SessionKey $key): ?StoredSession
    {
        $values = $this->run($this->client(), self::FIND, [self::SESSION.$key->value], []);

        if ($values === []) {
            return null;
        }

        $strings = array_values(array_filter($values, is_string(...)));

        if (count($values) !== 9 || count($strings) !== 9) {
            throw $this->unexpected('find', $values);
        }

        [$actor, $class, $connection, $method, $factors, $idpSession, $generation, $issued, $seen] = $strings;

        try {
            return new StoredSession(
                $key,
                ActorId::fromString($actor),
                ActorClass::from($class),
                new ConnectionId($connection),
                LoginMethod::from($method),
                LocalFactors::from($factors),
                $idpSession === '' ? null : new IdpSessionId($idpSession),
                new CredentialGeneration((int) $generation),
                $this->instant($issued),
                $this->instant($seen),
            );
        } catch (Throwable $unreadable) {
            throw new UnexpectedValueException('The session store holds a session it cannot read.', 0, $unreadable);
        }
    }

    #[Override]
    public function touch(StoredSession $session, DateTimeImmutable $expiresAt): bool
    {
        $ttl = $this->ttlMilliseconds($expiresAt);

        return $this->run($this->client(), self::TOUCH, [self::SESSION.$session->key->value], [
            (string) $ttl,
            $this->microseconds($session->lastSeenAt),
        ]) === [1];
    }

    #[Override]
    public function end(SessionKey $key): bool
    {
        $client = $this->client();

        return $this->run($client, self::END, [self::SESSION.$key->value], [$this->prefix($client).self::SESSION.$key->value]) === [1];
    }

    #[Override]
    public function endActor(ActorId $actor): int
    {
        return $this->endSet(self::OF_ACTOR.$actor->toString());
    }

    #[Override]
    public function endIdpSession(ConnectionId $connection, IdpSessionId $idpSession): int
    {
        return $this->endSet(self::idpSessionSet($connection, $idpSession));
    }

    /**
     * The name of the set of the sessions from an IdP session, below the connection's prefix. The
     * IdP session id is hashed, so any id the provider sends makes a key of one form.
     */
    public static function idpSessionSet(ConnectionId $connection, IdpSessionId $idpSession): string
    {
        return self::OF_IDP_SESSION.$connection->value.':'.hash('sha256', $idpSession->value);
    }

    private function endSet(string $set): int
    {
        $result = $this->run($this->client(), self::END_SET, [$set], []);

        return count($result) === 1 && is_int($result[0]) ? $result[0] : throw $this->unexpected('end', $result);
    }

    /**
     * The sets a session goes in, below the connection's prefix.
     *
     * @return list<string>
     */
    private function sets(StoredSession $session): array
    {
        $sets = [self::OF_ACTOR.$session->actor->toString()];

        if ($session->idpSession instanceof IdpSessionId) {
            $sets[] = self::idpSessionSet($session->connection, $session->idpSession);
        }

        return $sets;
    }

    private function client(): PhpRedisConnection
    {
        $connection = $this->redis->connection($this->connection);

        if (! $connection instanceof PhpRedisConnection || $connection instanceof PhpRedisClusterConnection) {
            throw new LogicException(sprintf(
                'The session store needs a phpredis connection to one Valkey primary; the Redis connection [%s] is a %s.',
                $this->connection ?? 'default',
                get_debug_type($connection),
            ));
        }

        return $connection;
    }

    /**
     * The prefix phpredis puts in front of every key, which the scripts store in the sets.
     */
    private function prefix(PhpRedisConnection $connection): string
    {
        $client = $connection->client();

        return $client instanceof Redis ? $client->_prefix('') : '';
    }

    /**
     * Runs a script in one EVAL through the connection; phpredis puts the prefix in front of each
     * of $keys. An integer answer comes back as a list of it.
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

        if (is_int($result)) {
            return [$result];
        }

        if (! is_array($result) || ! array_is_list($result)) {
            throw new UnexpectedValueException(sprintf(
                'The session store\'s script failed: %s',
                ($client instanceof Redis ? $client->getLastError() : null) ?? get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * The milliseconds from the Clock's time to $until, rounded up, so the copy never expires
     * before $until.
     *
     * @throws LogicException when $until is not after the Clock's time
     */
    private function ttlMilliseconds(DateTimeImmutable $until): int
    {
        $now = $this->clock->now();

        if ($until <= $now) {
            throw new LogicException('A session is kept until a time after now; its end has passed.');
        }

        return max(1, intdiv((int) $this->microseconds($until) - (int) $this->microseconds($now) + 999, 1000));
    }

    private function microseconds(DateTimeImmutable $time): string
    {
        return $time->format('U').$time->format('u');
    }

    private function instant(string $microseconds): DateTimeImmutable
    {
        if (preg_match('/\A[1-9][0-9]{6,}\z/', $microseconds) !== 1) {
            throw new UnexpectedValueException('The session store holds a time out of its form.');
        }

        $instant = DateTimeImmutable::createFromFormat('U.u', substr($microseconds, 0, -6).'.'.substr($microseconds, -6), new DateTimeZone('UTC'));

        return $instant instanceof DateTimeImmutable
            ? $instant->setTimezone(new DateTimeZone('UTC'))
            : throw new UnexpectedValueException('The session store holds a time out of its form.');
    }

    private function unexpected(string $operation, mixed $result): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf(
            'The session store\'s %s got an answer it does not know: %s',
            $operation,
            get_debug_type($result),
        ));
    }
}

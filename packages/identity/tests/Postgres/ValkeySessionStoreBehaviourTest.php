<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Adapter\ValkeySessionStore;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use Cbox\Cms\Identity\Tests\Sessions\SessionStoreBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Override;

/**
 * SessionStoreBehaviour against ValkeySessionStore on real Valkey, through the testkit's RealValkey
 * harness: the test database index and a key prefix per run, removed after each test. It also
 * shows what only Valkey shows: the keys a session leaves and their time to live, and that ending
 * a set leaves no key behind.
 */
final class ValkeySessionStoreBehaviourTest extends TestCase
{
    use RealValkey;
    use SessionStoreBehaviour;

    private ?FakeClock $clock = null;

    #[Override]
    protected function sessionStore(): SessionStore
    {
        return new ValkeySessionStore(app(Factory::class), $this->clock());
    }

    #[Override]
    protected function now(): DateTimeImmutable
    {
        return $this->clock()->now();
    }

    public function test_the_container_binds_the_valkey_store(): void
    {
        self::assertInstanceOf(ValkeySessionStore::class, app(SessionStore::class));
    }

    public function test_a_session_and_its_sets_live_until_its_end_and_ending_them_leaves_no_key(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession(idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated);
        $store->put($session, $this->now()->modify('+30 minutes'));

        $redis = $this->redis();
        $sessionKey = ValkeySessionStore::SESSION.$session->key->value;
        $actorSet = ValkeySessionStore::OF_ACTOR.$session->actor->toString();
        $idpSet = ValkeySessionStore::idpSessionSet(new ConnectionId('entra'), new IdpSessionId('sid-1'));

        foreach ([$sessionKey, $actorSet, $idpSet] as $key) {
            $ttl = $redis->command('pttl', [$key]);
            self::assertIsInt($ttl);
            self::assertGreaterThan(29 * 60 * 1000, $ttl, $key);
            self::assertLessThanOrEqual(30 * 60 * 1000, $ttl, $key);
        }

        self::assertStringNotContainsString('sid-1', $idpSet);
        self::assertSame(1, $store->endIdpSession(new ConnectionId('entra'), new IdpSessionId('sid-1')));

        foreach ([$sessionKey, $actorSet, $idpSet] as $key) {
            self::assertSame(0, $redis->command('exists', [$key]), $key);
        }
    }

    private function redis(): PhpRedisConnection
    {
        $connection = app(Factory::class)->connection();
        self::assertInstanceOf(PhpRedisConnection::class, $connection);

        return $connection;
    }

    private function clock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable('2026-10-02T09:00:00Z'));
    }
}

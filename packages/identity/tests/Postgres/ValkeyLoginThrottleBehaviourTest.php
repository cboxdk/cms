<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\Login\Adapter\ValkeyLoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\Tests\Login\LoginThrottleBehaviour;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Override;

/**
 * LoginThrottleBehaviour against ValkeyLoginThrottle on real Valkey, through the testkit's
 * RealValkey harness, and what only Valkey shows: the container binds it with the limits of
 * cbox-cms.identity.login.throttle, a count expires its window after its first attempt, and no key
 * holds an identifier or an address.
 */
final class ValkeyLoginThrottleBehaviourTest extends TestCase
{
    use LoginThrottleBehaviour;
    use RealValkey;

    #[Override]
    protected function loginThrottle(LoginThrottleSettings $settings): LoginThrottle
    {
        return new ValkeyLoginThrottle(app(Factory::class), $settings);
    }

    public function test_the_container_binds_the_valkey_throttle_with_the_configured_limits(): void
    {
        $settings = app(LoginThrottleSettings::class);

        self::assertInstanceOf(ValkeyLoginThrottle::class, app(LoginThrottle::class));
        self::assertSame([5, 900, 50, 900], [$settings->identifier->attempts, $settings->identifier->windowSeconds, $settings->ip->attempts, $settings->ip->windowSeconds]);
    }

    public function test_a_count_expires_its_window_after_its_first_attempt_and_holds_no_identifier_or_address(): void
    {
        $throttle = $this->loginThrottle(self::settings(identifier: 5, ip: 50, window: 600));
        $keys = LoginThrottleKeys::of(new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'));

        $throttle->hit($keys);
        $throttle->hit($keys);

        $redis = $this->redis();

        foreach ([ThrottleScope::Identifier, ThrottleScope::Ip] as $scope) {
            $ttl = $redis->command('pttl', [ValkeyLoginThrottle::key($scope, $keys)]);
            self::assertIsInt($ttl);
            self::assertGreaterThan(590 * 1000, $ttl, $scope->value);
            self::assertLessThanOrEqual(600 * 1000, $ttl, $scope->value);
        }

        $stored = implode(' ', app(ValkeyRun::class)->keys());

        self::assertStringContainsString(ValkeyLoginThrottle::KEY.'identifier:'.$keys->identifier, $stored);
        self::assertStringNotContainsString('ada', $stored);
        self::assertStringNotContainsString('192.0.2.1', $stored);
    }

    private function redis(): PhpRedisConnection
    {
        $connection = app(Factory::class)->connection();
        self::assertInstanceOf(PhpRedisConnection::class, $connection);

        return $connection;
    }
}

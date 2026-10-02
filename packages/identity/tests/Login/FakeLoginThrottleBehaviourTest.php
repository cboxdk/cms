<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login;

use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\Tests\Login\Fakes\FakeLoginThrottle;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * LoginThrottleBehaviour against the fake the login tests use, and what only a clock that moves
 * shows: a count lives for its window from its first attempt, later attempts do not move it, and
 * the identifier may try again once it has passed.
 */
final class FakeLoginThrottleBehaviourTest extends TestCase
{
    use LoginThrottleBehaviour;

    private ?FakeClock $clock = null;

    #[Override]
    protected function loginThrottle(LoginThrottleSettings $settings): LoginThrottle
    {
        return new FakeLoginThrottle($settings, $this->clock());
    }

    public function test_a_count_lives_for_its_window_from_its_first_attempt(): void
    {
        $throttle = new FakeLoginThrottle(self::settings(identifier: 2, ip: 100, window: 600), $this->clock());
        $keys = LoginThrottleKeys::of('ada@example.org', '192.0.2.1');

        self::assertNull($throttle->hit($keys));
        $this->clock()->advance(new DateInterval('PT9M'));
        self::assertNull($throttle->hit($keys));
        self::assertSame(ThrottleScope::Identifier, $throttle->hit($keys));
        self::assertSame(3, $throttle->count(ThrottleScope::Identifier, $keys));

        $this->clock()->advance(new DateInterval('PT1M'));

        self::assertSame(0, $throttle->count(ThrottleScope::Identifier, $keys));
        self::assertNull($throttle->hit($keys));
    }

    private function clock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable('2026-10-03T09:00:00Z'));
    }
}

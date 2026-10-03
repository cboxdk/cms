<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login;

use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\Login\Boundary\LoginInput;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleLimit;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every LoginThrottle does, run against ValkeyLoginThrottle and FakeLoginThrottle (GUARDRAILS
 * 9): it lets an identifier make its scope's attempts and refuses the next, whatever the case and
 * the white space at either end of the identifier; it refuses an IP address above its own limit
 * whatever identifiers it tries; and a login that succeeds clears its identifier's count and takes
 * itself off its address's.
 */
trait LoginThrottleBehaviour
{
    /** A new throttle with no counts and the limits given. */
    abstract protected function loginThrottle(LoginThrottleSettings $settings): LoginThrottle;

    #[Test]
    public function it_lets_an_identifier_make_its_attempts_and_refuses_the_next(): void
    {
        $throttle = $this->loginThrottle(self::settings(identifier: 3, ip: 100));

        foreach (range(1, 3) as $attempt) {
            Assert::assertNull($throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'))), 'attempt '.$attempt);
        }

        Assert::assertSame(ThrottleScope::Identifier, $throttle->hit(LoginThrottleKeys::of(LoginInput::login(' Ada@Example.org ')->identifier ?? Assert::fail('The identifier is unreadable.'), new ClientAddress('198.51.100.7'))));
        Assert::assertSame(ThrottleScope::Identifier, $throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'))));
        Assert::assertNull($throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('grace@example.org'), new ClientAddress('192.0.2.1'))));
    }

    #[Test]
    public function it_refuses_an_address_above_its_limit_whatever_identifiers_it_tries(): void
    {
        $throttle = $this->loginThrottle(self::settings(identifier: 5, ip: 4));

        foreach (['a', 'b', 'c', 'd'] as $name) {
            Assert::assertNull($throttle->hit(LoginThrottleKeys::of(new LoginIdentifier($name.'@example.org'), new ClientAddress('192.0.2.1'))));
        }

        Assert::assertSame(ThrottleScope::Ip, $throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('e@example.org'), new ClientAddress('192.0.2.1'))));
        Assert::assertNull($throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('e@example.org'), new ClientAddress('192.0.2.2'))));
    }

    #[Test]
    public function a_login_that_succeeds_clears_its_identifier_and_takes_itself_off_its_address(): void
    {
        $throttle = $this->loginThrottle(self::settings(identifier: 2, ip: 3));
        $ada = LoginThrottleKeys::of(new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'));

        Assert::assertNull($throttle->hit($ada));
        Assert::assertNull($throttle->hit($ada));
        $throttle->succeeded($ada);

        Assert::assertNull($throttle->hit($ada));
        Assert::assertNull($throttle->hit($ada));
        Assert::assertSame(ThrottleScope::Identifier, $throttle->hit($ada));

        // On another address: without the success taken off it, grace's second attempt would be
        // the address's fourth.
        $lin = LoginThrottleKeys::of(new LoginIdentifier('lin@example.org'), new ClientAddress('192.0.2.9'));
        $grace = LoginThrottleKeys::of(new LoginIdentifier('grace@example.org'), new ClientAddress('192.0.2.9'));

        Assert::assertNull($throttle->hit($lin));
        Assert::assertNull($throttle->hit($lin));
        $throttle->succeeded($lin);

        Assert::assertNull($throttle->hit($grace));
        Assert::assertNull($throttle->hit($grace));
        Assert::assertSame(ThrottleScope::Identifier, $throttle->hit($grace));
        Assert::assertSame(ThrottleScope::Ip, $throttle->hit(LoginThrottleKeys::of(new LoginIdentifier('carol@example.org'), new ClientAddress('192.0.2.9'))));
    }

    #[Test]
    public function taking_back_an_attempt_it_never_counted_changes_nothing(): void
    {
        $throttle = $this->loginThrottle(self::settings(identifier: 1, ip: 1));
        $keys = LoginThrottleKeys::of(new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'));

        $throttle->succeeded($keys);

        Assert::assertNull($throttle->hit($keys));
        Assert::assertSame(ThrottleScope::Identifier, $throttle->hit($keys));
    }

    protected static function settings(int $identifier, int $ip, int $window = 900): LoginThrottleSettings
    {
        return new LoginThrottleSettings(new ThrottleLimit($identifier, $window), new ThrottleLimit($ip, $window));
    }
}

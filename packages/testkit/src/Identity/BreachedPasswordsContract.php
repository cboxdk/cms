<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for BreachedPasswords (GUARDRAILS 2.3 and 9, PRD 5.16). The fake and
 * every real implementation run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * fresh harness for each case:
 *
 *     final class FakeBreachedPasswordsContractTest extends TestCase
 *     {
 *         use BreachedPasswordsContract;
 *
 *         protected function harness(): BreachedPasswordsHarness
 *         {
 *             return new FakeBreachedPasswords;
 *         }
 *     }
 *
 * The cases: a password nobody breached is not breached, a breached one is and the others stay
 * clean, the match is exact (case, a character more, Unicode), a check changes nothing, and a check
 * that cannot be made fails closed with the retryable breached_passwords_unavailable, whose
 * message does not hold the password.
 */
#[Experimental]
trait BreachedPasswordsContract
{
    /**
     * A fresh harness: no password breached, the service answering.
     */
    abstract protected function harness(): BreachedPasswordsHarness;

    #[Test]
    public function a_password_nobody_breached_is_not_breached(): void
    {
        $passwords = $this->harness()->breachedPasswords();

        Assert::assertFalse($passwords->isBreached(new Password('correct horse battery staple 41')));
    }

    #[Test]
    public function a_breached_password_is_breached_and_the_others_stay_clean(): void
    {
        $harness = $this->harness();
        $harness->breach(new Password('Summer2026!Summer'));
        $harness->breach(new Password('qwertyuiop123'));
        $passwords = $harness->breachedPasswords();

        Assert::assertTrue($passwords->isBreached(new Password('Summer2026!Summer')));
        Assert::assertTrue($passwords->isBreached(new Password('qwertyuiop123')));
        Assert::assertFalse($passwords->isBreached(new Password('a long and quite unusual sentence')));
    }

    #[Test]
    public function the_match_is_exact(): void
    {
        $harness = $this->harness();
        $harness->breach(new Password('Summer2026!Summer'));
        $harness->breach(new Password('rødgrød med fløde'));
        $passwords = $harness->breachedPasswords();

        Assert::assertFalse($passwords->isBreached(new Password('summer2026!summer')));
        Assert::assertFalse($passwords->isBreached(new Password('Summer2026!Summer ')));
        Assert::assertFalse($passwords->isBreached(new Password('Summer2026!Summe')));
        Assert::assertTrue($passwords->isBreached(new Password('rødgrød med fløde')));
        Assert::assertFalse($passwords->isBreached(new Password('rodgrod med flode')));
    }

    #[Test]
    public function a_check_changes_nothing(): void
    {
        $harness = $this->harness();
        $harness->breach(new Password('Summer2026!Summer'));
        $passwords = $harness->breachedPasswords();

        foreach ([1, 2, 3] as $round) {
            Assert::assertTrue($passwords->isBreached(new Password('Summer2026!Summer')), sprintf('Check %d.', $round));
            Assert::assertFalse($passwords->isBreached(new Password('correct horse battery staple 41')), sprintf('Check %d.', $round));
        }
    }

    #[Test]
    public function a_check_that_cannot_be_made_fails_closed_and_is_retryable(): void
    {
        $harness = $this->harness();
        $harness->breach(new Password('Summer2026!Summer'));
        $passwords = $harness->breachedPasswords();
        $harness->goDown();

        foreach (['Summer2026!Summer', 'correct horse battery staple 41'] as $password) {
            try {
                $passwords->isBreached(new Password($password));
                Assert::fail('A check that cannot be made answered.');
            } catch (BreachedPasswordsUnavailable $unavailable) {
                Assert::assertSame('breached_passwords_unavailable', BreachedPasswordsUnavailable::CODE);
                Assert::assertTrue(ErrorCode::from(BreachedPasswordsUnavailable::CODE)->entry()->retryable);
                Assert::assertStringNotContainsString($password, $unavailable->getMessage());
                Assert::assertStringNotContainsString(substr(strtoupper(sha1($password)), 5), $unavailable->getMessage());
            }
        }
    }
}

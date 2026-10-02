<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalAccountMissing;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetRefused;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The shared contract suite for LocalCredentialStore (GUARDRAILS 2.3 and 9, PRD 5.16). The fake and
 * every real store run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * fresh harness for each case:
 *
 *     final class FakeLocalCredentialStoreContractTest extends TestCase
 *     {
 *         use LocalCredentialStoreContract;
 *
 *         protected function harness(): LocalCredentialStoreHarness
 *         {
 *             return new FakeLocalCredentialStore;
 *         }
 *     }
 *
 * The cases: a bound account is found by its login and its actor at version 1, made and set at the
 * Clock's time; an unknown login or actor has none; a login or an actor is bound once, and an
 * actor that does not exist not at all, with messages that never hold the login; a rehash replaces
 * only the hash the caller verified and keeps when the password was set; a password change sets
 * the hash and the time; a reset token sets a password once and not after it expires, an unknown
 * token is refused as a used one is, a token is looked up without being taken and only while it is
 * usable, a reset takes the account's other unused tokens, a token is
 * issued only for an account and with an expiry after now, and a prune removes exactly the tokens
 * used or expired before its time.
 */
#[Experimental]
trait LocalCredentialStoreContract
{
    /** Three Argon2id hashes in PHP's encoded form; a store keeps them as given. */
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0c2FsdA$9bSmXR8xsVQBq0xKC6hU3nPVUbKjsr6Ln0QGqkPZ+9I';

    private const string OTHER_HASH = '$argon2id$v=19$m=65536,t=4,p=1$b3RoZXJvdGhlcm90aGVy$8M0yTqHfV5d9yX4RJ2pa6N3l6v0w1A3SEbSQ1gNfj2M';

    private const string THIRD_HASH = '$argon2id$v=19$m=19456,t=2,p=1$dGhpcmR0aGlyZHRoaXJk$q6l3vGJ1Ea+TT8uE3BaYbE0+P1s0VbN1kYv3c3gq0Ac';

    private const string LOGIN = 'ada.lovelace@example.org';

    /**
     * A fresh harness: a store with no account and no token.
     */
    abstract protected function harness(): LocalCredentialStoreHarness;

    #[Test]
    public function a_bound_account_is_found_by_its_login_and_its_actor(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $now = $harness->clock()->now();

        $bound = $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $found = $harness->store()->find(new LoginIdentifier(self::LOGIN));

        foreach ([$bound, $found, $harness->store()->ofActor($actor)] as $account) {
            Assert::assertInstanceOf(LocalAccount::class, $account);
            Assert::assertTrue($account->actor->equals($actor));
            Assert::assertSame(self::LOGIN, $account->login->value);
            Assert::assertSame(self::HASH, $account->hash->value);
            Assert::assertSame(LocalAccount::FIRST_VERSION, $account->version);
            Assert::assertSame($this->instant($now), $this->instant($account->createdAt));
            Assert::assertSame($this->instant($now), $this->instant($account->passwordChangedAt));
        }
    }

    #[Test]
    public function an_unknown_login_or_actor_has_no_account(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));

        Assert::assertNull($harness->store()->find(new LoginIdentifier('grace.hopper@example.org')));
        Assert::assertNull($harness->store()->find(new LoginIdentifier('ada.lovelace@example.or')));
        Assert::assertNull($harness->store()->ofActor($harness->actor()));
    }

    #[Test]
    public function a_login_is_bound_once_and_the_refusal_does_not_hold_it(): void
    {
        $harness = $this->harness();
        $first = $harness->actor();
        $second = $harness->actor();
        $harness->store()->bind($first, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));

        $refused = $this->refusal(static fn (): LocalAccount => $harness->store()->bind($second, new LoginIdentifier(self::LOGIN), new PasswordHash(self::OTHER_HASH)));

        Assert::assertInstanceOf(LocalAccountExists::class, $refused);
        Assert::assertStringNotContainsString(self::LOGIN, $refused->getMessage());
        Assert::assertNull($harness->store()->ofActor($second));
        Assert::assertSame(self::HASH, $harness->store()->find(new LoginIdentifier(self::LOGIN))?->hash->value);
    }

    #[Test]
    public function an_actor_is_bound_once(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));

        $refused = $this->refusal(static fn (): LocalAccount => $harness->store()->bind($actor, new LoginIdentifier('another.login@example.org'), new PasswordHash(self::OTHER_HASH)));

        Assert::assertInstanceOf(LocalAccountExists::class, $refused);
        Assert::assertNull($harness->store()->find(new LoginIdentifier('another.login@example.org')));
        Assert::assertSame(self::HASH, $harness->store()->ofActor($actor)?->hash->value);
    }

    #[Test]
    public function an_actor_that_does_not_exist_is_not_bound(): void
    {
        $harness = $this->harness();
        $nobody = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000dead');

        $refused = $this->refusal(static fn (): LocalAccount => $harness->store()->bind($nobody, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH)));

        Assert::assertInstanceOf(InvalidIdentity::class, $refused);
        Assert::assertNull($harness->store()->find(new LoginIdentifier(self::LOGIN)));
    }

    #[Test]
    public function a_rehash_replaces_only_the_hash_the_caller_verified_and_keeps_when_the_password_was_set(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $set = $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH))->passwordChangedAt;
        $harness->clock()->advance(new DateInterval('PT1M'));

        $stale = $harness->store()->rehash($actor, new PasswordHash(self::OTHER_HASH), new PasswordHash(self::THIRD_HASH));
        $replaced = $harness->store()->rehash($actor, new PasswordHash(self::HASH), new PasswordHash(self::THIRD_HASH));
        $again = $harness->store()->rehash($actor, new PasswordHash(self::HASH), new PasswordHash(self::OTHER_HASH));
        $account = $harness->store()->ofActor($actor);

        Assert::assertFalse($stale);
        Assert::assertTrue($replaced);
        Assert::assertFalse($again);
        Assert::assertInstanceOf(LocalAccount::class, $account);
        Assert::assertSame(self::THIRD_HASH, $account->hash->value);
        Assert::assertSame(2, $account->version);
        Assert::assertSame($this->instant($set), $this->instant($account->passwordChangedAt));
        Assert::assertFalse($harness->store()->rehash($harness->actor(), new PasswordHash(self::HASH), new PasswordHash(self::OTHER_HASH)));
    }

    #[Test]
    public function a_password_change_sets_the_hash_and_the_time(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $harness->clock()->advance(new DateInterval('PT10M'));

        $changed = $harness->store()->changePassword($actor, new PasswordHash(self::OTHER_HASH));
        $found = $harness->store()->find(new LoginIdentifier(self::LOGIN));

        Assert::assertSame(self::OTHER_HASH, $found?->hash->value);
        Assert::assertSame(2, $changed->version);
        Assert::assertSame(2, $found->version);
        Assert::assertSame($this->instant($harness->clock()->now()), $this->instant($found->passwordChangedAt));
        Assert::assertInstanceOf(LocalAccountMissing::class, $this->refusal(static fn (): LocalAccount => $harness->store()->changePassword($harness->actor(), new PasswordHash(self::HASH))));
    }

    #[Test]
    public function a_reset_token_sets_a_password_once(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $token = $harness->store()->issueResetToken($actor, $harness->clock()->now()->add(new DateInterval('PT1H')));
        $harness->clock()->advance(new DateInterval('PT5M'));

        $reset = $harness->store()->resetPassword($token, new PasswordHash(self::OTHER_HASH));
        $again = $this->refusal(static fn (): LocalAccount => $harness->store()->resetPassword($token, new PasswordHash(self::THIRD_HASH)));

        Assert::assertTrue($reset->actor->equals($actor));
        Assert::assertSame(self::OTHER_HASH, $harness->store()->ofActor($actor)?->hash->value);
        Assert::assertSame($this->instant($harness->clock()->now()), $this->instant($reset->passwordChangedAt));
        Assert::assertInstanceOf(PasswordResetRefused::class, $again);
        Assert::assertStringNotContainsString($token->reveal(), $again->getMessage());
    }

    #[Test]
    public function a_reset_token_is_refused_once_it_expires(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $token = $harness->store()->issueResetToken($actor, $harness->clock()->now()->add(new DateInterval('PT1H')));
        $harness->clock()->advance(new DateInterval('PT1H'));

        Assert::assertInstanceOf(PasswordResetRefused::class, $this->refusal(static fn (): LocalAccount => $harness->store()->resetPassword($token, new PasswordHash(self::OTHER_HASH))));
        Assert::assertSame(self::HASH, $harness->store()->ofActor($actor)?->hash->value);
    }

    #[Test]
    public function an_unknown_reset_token_is_refused_as_a_used_one_is(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $harness->store()->issueResetToken($actor, $harness->clock()->now()->add(new DateInterval('PT1H')));
        $unknown = PasswordResetToken::fromSecret(str_repeat("\x07", PasswordResetToken::SECRET_BYTES));

        $refused = $this->refusal(static fn (): LocalAccount => $harness->store()->resetPassword($unknown, new PasswordHash(self::OTHER_HASH)));

        Assert::assertInstanceOf(PasswordResetRefused::class, $refused);
        Assert::assertSame(PasswordResetRefused::token()->getMessage(), $refused->getMessage());
        Assert::assertSame(self::HASH, $harness->store()->ofActor($actor)?->hash->value);
    }

    #[Test]
    public function a_reset_token_is_issued_only_for_an_account_and_with_an_expiry_after_now(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $now = $harness->clock()->now();

        Assert::assertInstanceOf(LocalAccountMissing::class, $this->refusal(static fn (): PasswordResetToken => $harness->store()->issueResetToken($actor, $now->add(new DateInterval('PT1H')))));

        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));

        Assert::assertInstanceOf(InvalidIdentity::class, $this->refusal(static fn (): PasswordResetToken => $harness->store()->issueResetToken($actor, $now)));

        $first = $harness->store()->issueResetToken($actor, $now->add(new DateInterval('PT1H')));
        $second = $harness->store()->issueResetToken($actor, $now->add(new DateInterval('PT1H')));

        Assert::assertNotSame($first->reveal(), $second->reveal());
        Assert::assertInstanceOf(PasswordResetToken::class, PasswordResetToken::parse($first->reveal()));
    }

    #[Test]
    public function a_token_is_looked_up_without_being_taken_and_only_while_it_is_usable(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $token = $harness->store()->issueResetToken($actor, $harness->clock()->now()->add(new DateInterval('PT1H')));
        $expiring = $harness->store()->issueResetToken($actor, $harness->clock()->now()->add(new DateInterval('PT30M')));

        Assert::assertTrue($harness->store()->resetTokenActor($token)?->equals($actor));
        Assert::assertNull($harness->store()->resetTokenActor(PasswordResetToken::fromSecret(str_repeat("\x07", PasswordResetToken::SECRET_BYTES))));

        $harness->clock()->advance(new DateInterval('PT30M'));

        Assert::assertNull($harness->store()->resetTokenActor($expiring));

        // The lookups took nothing: the token still sets a password, once.
        Assert::assertSame(self::OTHER_HASH, $harness->store()->resetPassword($token, new PasswordHash(self::OTHER_HASH))->hash->value);
        Assert::assertNull($harness->store()->resetTokenActor($token));
    }

    #[Test]
    public function a_reset_takes_every_other_unused_token_of_the_account(): void
    {
        $harness = $this->harness();
        $actor = $harness->actor();
        $other = $harness->actor();
        $harness->store()->bind($actor, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $harness->store()->bind($other, new LoginIdentifier('grace.hopper@example.org'), new PasswordHash(self::HASH));
        $expiry = $harness->clock()->now()->add(new DateInterval('PT1H'));
        $earlier = $harness->store()->issueResetToken($actor, $expiry);
        $used = $harness->store()->issueResetToken($actor, $expiry);
        $others = $harness->store()->issueResetToken($other, $expiry);
        $harness->clock()->advance(new DateInterval('PT1M'));

        $harness->store()->resetPassword($used, new PasswordHash(self::OTHER_HASH));
        $refused = $this->refusal(static fn (): LocalAccount => $harness->store()->resetPassword($earlier, new PasswordHash(self::THIRD_HASH)));

        Assert::assertInstanceOf(PasswordResetRefused::class, $refused);
        Assert::assertSame(self::OTHER_HASH, $harness->store()->ofActor($actor)?->hash->value);
        Assert::assertSame(self::THIRD_HASH, $harness->store()->resetPassword($others, new PasswordHash(self::THIRD_HASH))->hash->value);
    }

    #[Test]
    public function a_prune_removes_exactly_the_tokens_used_or_expired_before_its_time(): void
    {
        $harness = $this->harness();
        $start = $harness->clock()->now();
        $used = $harness->actor();
        $expired = $harness->actor();
        $usable = $harness->actor();
        $harness->store()->bind($used, new LoginIdentifier(self::LOGIN), new PasswordHash(self::HASH));
        $harness->store()->bind($expired, new LoginIdentifier('grace.hopper@example.org'), new PasswordHash(self::HASH));
        $harness->store()->bind($usable, new LoginIdentifier('katherine.johnson@example.org'), new PasswordHash(self::HASH));
        $first = $harness->store()->issueResetToken($used, $start->add(new DateInterval('PT1H')));
        $harness->store()->issueResetToken($expired, $start->add(new DateInterval('PT1H')));
        $kept = $harness->store()->issueResetToken($usable, $start->add(new DateInterval('PT3H')));
        $harness->clock()->advance(new DateInterval('PT5M'));
        $harness->store()->resetPassword($first, new PasswordHash(self::OTHER_HASH));

        Assert::assertSame(0, $harness->store()->pruneResetTokens($start->add(new DateInterval('PT5M'))));
        Assert::assertSame(1, $harness->store()->pruneResetTokens($start->add(new DateInterval('PT1H'))));
        Assert::assertSame(1, $harness->store()->pruneResetTokens($start->add(new DateInterval('PT2H'))));
        Assert::assertSame(0, $harness->store()->pruneResetTokens($start->add(new DateInterval('PT3H'))));
        Assert::assertSame(self::THIRD_HASH, $harness->store()->resetPassword($kept, new PasswordHash(self::THIRD_HASH))->hash->value);
    }

    /**
     * @param  Closure(): object  $call
     */
    private function refusal(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        Assert::fail('The store accepted a call it must refuse.');
    }

    private function instant(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.uP');
    }
}

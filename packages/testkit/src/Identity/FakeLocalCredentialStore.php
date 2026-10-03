<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalAccountMissing;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetRefused;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * The in-memory fake of LocalCredentialStore (GUARDRAILS 2.3), and its own harness for the shared
 * suite LocalCredentialStoreContract. It holds accounts and reset tokens in memory, at the
 * FakeClock's time, and binds an account only to an actor its ActorDirectory knows: by default a
 * FakeIdentity of its own, in which actor() makes active staff actors; a test that registers
 * actors elsewhere gives that directory. It keeps a reset token only as its SHA-256, as a real
 * store does, and resetTokens() counts the tokens it holds.
 */
#[Experimental]
final class FakeLocalCredentialStore implements LocalCredentialStore, LocalCredentialStoreHarness
{
    /** @var array<string, LocalAccount> by actor id */
    private array $accounts = [];

    /** @var array<string, FakeResetToken> by the SHA-256 of the token */
    private array $tokens = [];

    private readonly ActorDirectory $actors;

    public function __construct(
        private readonly FakeClock $clock = new FakeClock,
        ?ActorDirectory $actors = null,
    ) {
        $this->actors = $actors ?? new FakeIdentity($clock);
    }

    #[Override]
    public function bind(ActorId $actor, LoginIdentifier $login, PasswordHash $hash): LocalAccount
    {
        if (! $this->actors->find($actor) instanceof Actor) {
            throw InvalidIdentity::unknownActor($actor);
        }

        if (isset($this->accounts[$actor->toString()])) {
            throw LocalAccountExists::forActor($actor);
        }

        if ($this->find($login) instanceof LocalAccount) {
            throw LocalAccountExists::forLogin($actor);
        }

        $now = $this->clock->now();

        return $this->accounts[$actor->toString()] = new LocalAccount($actor, $login, $hash, LocalAccount::FIRST_VERSION, $now, $now);
    }

    #[Override]
    public function find(LoginIdentifier $login): ?LocalAccount
    {
        return array_find($this->accounts, static fn (LocalAccount $account): bool => $account->login->equals($login));
    }

    #[Override]
    public function ofActor(ActorId $actor): ?LocalAccount
    {
        return $this->accounts[$actor->toString()] ?? null;
    }

    #[Override]
    public function rehash(ActorId $actor, PasswordHash $verified, PasswordHash $rehashed): bool
    {
        $account = $this->ofActor($actor);

        if (! $account instanceof LocalAccount || ! $account->hash->equals($verified)) {
            return false;
        }

        $this->accounts[$actor->toString()] = new LocalAccount($actor, $account->login, $rehashed, $account->version + 1, $account->passwordChangedAt, $account->createdAt);

        return true;
    }

    #[Override]
    public function changePassword(ActorId $actor, PasswordHash $hash): LocalAccount
    {
        $account = $this->ofActor($actor) ?? throw LocalAccountMissing::of($actor);

        // Never before the account was made, as a store on a node whose clock is behind would date it.
        return $this->accounts[$actor->toString()] = new LocalAccount($actor, $account->login, $hash, $account->version + 1, max($this->clock->now(), $account->createdAt), $account->createdAt);
    }

    #[Override]
    public function issueResetToken(ActorId $actor, DateTimeImmutable $expiresAt): PasswordResetToken
    {
        if (! $this->ofActor($actor) instanceof LocalAccount) {
            throw LocalAccountMissing::of($actor);
        }

        $now = $this->clock->now();

        if ($expiresAt <= $now) {
            throw InvalidIdentity::expiry($expiresAt, $now);
        }

        $token = PasswordResetToken::fromSecret(random_bytes(PasswordResetToken::SECRET_BYTES));
        $this->tokens[$token->hash()] = new FakeResetToken($actor, $expiresAt, $now);

        return $token;
    }

    #[Override]
    public function resetTokenActor(PasswordResetToken $token): ?ActorId
    {
        $stored = $this->tokens[$token->hash()] ?? null;

        if (! $stored instanceof FakeResetToken || $stored->usedAt instanceof DateTimeImmutable || $stored->expiresAt <= $this->clock->now()) {
            return null;
        }

        return $stored->actor;
    }

    #[Override]
    public function resetPassword(PasswordResetToken $token, PasswordHash $hash): LocalAccount
    {
        $stored = $this->tokens[$token->hash()] ?? null;
        $now = $this->clock->now();

        if (! $stored instanceof FakeResetToken || $stored->usedAt instanceof DateTimeImmutable || $stored->expiresAt <= $now || ! $this->ofActor($stored->actor) instanceof LocalAccount) {
            throw PasswordResetRefused::token();
        }

        foreach ($this->tokens as $key => $other) {
            if ($other->actor->equals($stored->actor) && ! $other->usedAt instanceof DateTimeImmutable && $other->expiresAt > $now) {
                $this->tokens[$key] = $other->usedAt(max($now, $other->createdAt));
            }
        }

        return $this->changePassword($stored->actor, $hash);
    }

    #[Override]
    public function pruneResetTokens(DateTimeImmutable $before): int
    {
        $kept = array_filter($this->tokens, static fn (FakeResetToken $token): bool => ($token->usedAt ?? $token->expiresAt) >= $before);
        $pruned = count($this->tokens) - count($kept);
        $this->tokens = $kept;

        return $pruned;
    }

    /**
     * How many reset tokens the fake holds, used and expired ones included, for a test of pruning.
     */
    public function resetTokens(): int
    {
        return count($this->tokens);
    }

    #[Override]
    public function store(): LocalCredentialStore
    {
        return $this;
    }

    #[Override]
    public function clock(): FakeClock
    {
        return $this->clock;
    }

    /**
     * A new active staff actor in the fake's own FakeIdentity.
     *
     * @throws LogicException when the fake was given another ActorDirectory
     */
    #[Override]
    public function actor(): ActorId
    {
        if (! $this->actors instanceof FakeIdentity) {
            throw new LogicException('FakeLocalCredentialStore::actor() makes actors only in a FakeIdentity; register them in the directory the fake was given.');
        }

        return $this->actors->addActor(ActorClass::Staff)->id;
    }
}

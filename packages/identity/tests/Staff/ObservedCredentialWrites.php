<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Staff;

use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use Override;

/**
 * A LocalCredentialStore that tells what StaffWorld looked like when bind() was called: the commands
 * committed so far and the state of the actor bound. With $takenMeanwhile it refuses the bind with
 * LocalAccountExists, as a store does when another registration bound the login between the check
 * and the bind. Everything else goes to the store it wraps.
 */
final class ObservedCredentialWrites implements LocalCredentialStore
{
    /** @var list<string> at each bind, the commands committed and the state of the actor */
    public array $seen = [];

    public function __construct(
        private readonly LocalCredentialStore $store,
        private readonly StaffWorld $world,
        private readonly bool $takenMeanwhile = false,
    ) {}

    #[Override]
    public function bind(ActorId $actor, LoginIdentifier $login, PasswordHash $hash): LocalAccount
    {
        $this->seen[] = sprintf('%s; actor %s', implode(', ', $this->world->committed()), $this->world->find($actor)->state->value ?? 'missing');

        if ($this->takenMeanwhile) {
            throw LocalAccountExists::forLogin($actor);
        }

        return $this->store->bind($actor, $login, $hash);
    }

    #[Override]
    public function find(LoginIdentifier $login): ?LocalAccount
    {
        return $this->store->find($login);
    }

    #[Override]
    public function ofActor(ActorId $actor): ?LocalAccount
    {
        return $this->store->ofActor($actor);
    }

    #[Override]
    public function rehash(ActorId $actor, PasswordHash $verified, PasswordHash $rehashed): bool
    {
        return $this->store->rehash($actor, $verified, $rehashed);
    }

    #[Override]
    public function changePassword(ActorId $actor, PasswordHash $hash): LocalAccount
    {
        return $this->store->changePassword($actor, $hash);
    }

    #[Override]
    public function issueResetToken(ActorId $actor, DateTimeImmutable $expiresAt): PasswordResetToken
    {
        return $this->store->issueResetToken($actor, $expiresAt);
    }

    #[Override]
    public function resetPassword(PasswordResetToken $token, PasswordHash $hash): LocalAccount
    {
        return $this->store->resetPassword($token, $hash);
    }

    #[Override]
    public function resetTokenActor(PasswordResetToken $token): ?ActorId
    {
        return $this->store->resetTokenActor($token);
    }

    #[Override]
    public function pruneResetTokens(DateTimeImmutable $before): int
    {
        return $this->store->pruneResetTokens($before);
    }
}

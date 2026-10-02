<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Staff;

use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use Override;
use RuntimeException;

/**
 * A LocalCredentialStore whose writes fail, as a store fails when its database stops answering: it
 * reads from the store it wraps and throws on every write. A test of a registration uses it to show
 * what a failure after actor.register leaves.
 */
final readonly class FailingCredentialWrites implements LocalCredentialStore
{
    public const string MESSAGE = 'The credential store did not answer.';

    public function __construct(private LocalCredentialStore $store) {}

    #[Override]
    public function bind(ActorId $actor, LoginIdentifier $login, PasswordHash $hash): LocalAccount
    {
        throw new RuntimeException(self::MESSAGE);
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
        throw new RuntimeException(self::MESSAGE);
    }

    #[Override]
    public function changePassword(ActorId $actor, PasswordHash $hash): LocalAccount
    {
        throw new RuntimeException(self::MESSAGE);
    }

    #[Override]
    public function issueResetToken(ActorId $actor, DateTimeImmutable $expiresAt): PasswordResetToken
    {
        throw new RuntimeException(self::MESSAGE);
    }

    #[Override]
    public function resetPassword(PasswordResetToken $token, PasswordHash $hash): LocalAccount
    {
        throw new RuntimeException(self::MESSAGE);
    }
}

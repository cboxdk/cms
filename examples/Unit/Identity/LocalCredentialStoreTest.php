<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetRefused;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;

// A local account binds a login to an actor and keeps only the hash of its password (PRD 5.16).
// The code under test takes the contract; the test hands it the testkit's fake in place of the
// Postgres store, so it needs no database. It binds an account, verifies a password against the
// hash it finds by the login, and resets the password once with a token.

function localAccountHash(string $password): PasswordHash
{
    return new PasswordHash(password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]));
}

function localAccountLogin(LocalCredentialStore $store, string $email, string $password): ?ActorId
{
    $account = $store->find(LoginIdentifier::fromEmail(new EmailAddress($email)));

    return $account instanceof LocalAccount && password_verify($password, $account->hash->value) ? $account->actor : null;
}

it('binds an account, finds it by its login and resets its password once', function (): void {
    $store = new FakeLocalCredentialStore;
    $actor = $store->actor();

    $store->bind($actor, LoginIdentifier::fromEmail(new EmailAddress('Mette.Holm@example.com')), localAccountHash('a long and quite unusual sentence'));

    expect(localAccountLogin($store, 'mette.holm@example.com', 'a long and quite unusual sentence')?->equals($actor))->toBeTrue()
        ->and(localAccountLogin($store, 'mette.holm@example.com', 'a wrong sentence'))->toBeNull();

    $token = $store->issueResetToken($actor, $store->clock()->now()->modify('+60 minutes'));
    $store->resetPassword($token, localAccountHash('another long and unusual sentence'));

    expect(localAccountLogin($store, 'mette.holm@example.com', 'another long and unusual sentence')?->equals($actor))->toBeTrue()
        ->and(fn (): LocalAccount => $store->resetPassword($token, localAccountHash('a third long sentence')))->toThrow(PasswordResetRefused::class);
});

---
title: Local credential store
weight: 47
description: "The LocalCredentialStore contract: local accounts bound to actors with the hash of their password, rehashing, password changes and one-time reset tokens, the default store in the schema cms_identity on the identity connection, the testkit's FakeLocalCredentialStore and the shared suite LocalCredentialStoreContract with its harness."
---

# Local credential store

<!-- extension-point: Cbox\Cms\Contracts\Identity\LocalCredentialStore -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\LocalCredentialStoreHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\LocalCredentialStoreContract -->

A local account is a login and a password that belong to an actor of the actor register (PRD 5.16, "Lokale konti"), so there is one list of people. `Cbox\Cms\Contracts\Identity\LocalCredentialStore` keeps them. It is `#[Experimental]`.

## The contract

The store keeps hashes only. It never sees a password, and it keeps a reset token only as its SHA-256.

- `bind(ActorId, LoginIdentifier, PasswordHash): LocalAccount` binds a new account to an actor that exists, at version 1, made and set at the Clock's time. A login that is another account's, or an actor that has an account, throws `LocalAccountExists` with the code [`local_account_exists`](../../reference/errors.md#local_account_exists); an actor the register does not have throws `InvalidIdentity`.
- `find(LoginIdentifier): ?LocalAccount` and `ofActor(ActorId): ?LocalAccount` give the account, or null.
- `rehash(ActorId, PasswordHash $verified, PasswordHash $rehashed): bool` replaces the hash only while the account still has the hash the caller verified, and keeps when the password was set. A login calls it when the hash was made with other parameters than the installation's.
- `changePassword(ActorId, PasswordHash): LocalAccount` sets a new hash at the Clock's time, or throws `LocalAccountMissing` with [`local_account_missing`](../../reference/errors.md#local_account_missing).
- `issueResetToken(ActorId, DateTimeImmutable $expiresAt): PasswordResetToken` makes a token for an account, which expires at the time given, after now.
- `resetPassword(PasswordResetToken, PasswordHash): LocalAccount` takes a token once, before it expires, and sets the hash with it, both or neither. An unknown, used or expired token throws `PasswordResetRefused` with [`password_reset_token_invalid`](../../reference/errors.md#password_reset_token_invalid), the same for all three, so a caller cannot tell which tokens exist.

Every change of the hash adds 1 to the account's version. No message of the store holds a login, a hash or a token.

The values:

- `LoginIdentifier` is the email address in lower case, without white space or control characters. `fromEmail()` takes an `EmailAddress`; `typed()` reads what a login form took and gives null for anything else.
- `PasswordHash` is an Argon2id hash in PHP's encoded form, as `password_hash()` writes it with `PASSWORD_ARGON2ID`.
- `PasswordResetToken` is 256 random bits with a checksum, `cms_pr_` and 72 hex digits. `parse()` refuses a mistyped token before any lookup, and `reveal()` gives it to the code that builds the link.

Verifying a password is the caller's: it finds the account by the login and verifies the password against its hash. The identity module's [local connection](../../security/local-accounts.md#the-local-connection) does it, and verifies an unknown login against a fixed dummy hash, so it costs what a wrong password costs.

## The default: PostgresLocalCredentialStore

`cbox-cms.contracts` binds the contract to `Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore` of the identity module, unless the application names another class. It keeps the accounts in `cms_identity.local_accounts` and the tokens in `cms_identity.password_reset_tokens`, on the identity role's connection, which the app role cannot read (see [Credential store](../../security/credential-store.md)).

## The fake: FakeLocalCredentialStore

`Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore` holds accounts and tokens in memory at a `FakeClock`. It binds an account only to an actor its `ActorDirectory` knows: by default a `FakeIdentity` of its own, in which `actor()` makes active staff actors. This example is in the `Unit` suite:

<!-- example: examples/Unit/Identity/LocalCredentialStoreTest.php -->
```php
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
```

## Running the shared suite against a store

Every store runs the shared suite, the trait `Cbox\Cms\Testkit\Identity\LocalCredentialStoreContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `harness(): LocalCredentialStoreHarness`, which returns a fresh harness for each case. The harness has three methods: `store()` gives the store under test, with no account and no token; `clock()` gives the `FakeClock` the store reads its time from, which the suite moves; and `actor()` makes an actor of the register without an account. The identity module runs it against the fake and against `PostgresLocalCredentialStore` on real Postgres.

The cases cover an account found by its login and its actor, unknown logins and actors, a login and an actor bound once and an actor that does not exist not at all, a rehash that replaces only the verified hash, a password change, a reset token used once, refused after it expires and refused like an unknown one, and tokens issued only for an account and with an expiry after now.

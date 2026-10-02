<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Identity\LocalAccounts\Domain\Dto\Argon2idParameters;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\Tests\LocalAccounts\CountingPasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;
use PHPUnit\Framework\Assert;

// An unknown email costs what a wrong password costs (PRD 5.16): the local connection verifies an
// unknown login against the hasher's fixed dummy hash, exactly once, with the same Argon2id
// parameters as the account's hash a wrong password is verified against, so the time of a refusal
// does not tell which emails have an account. Both are refused with login_rejected.

/**
 * The memory, time and threads an Argon2id hash in PHP's encoded form was made with.
 */
function hashParameters(?PasswordHash $hash): string
{
    return $hash instanceof PasswordHash && preg_match('/\A\$(argon2id)\$v=19\$m=([0-9]+),t=([0-9]+),p=([0-9]+)\$/', $hash->value, $parts) === 1
        ? sprintf('%s m=%s t=%s p=%s', $parts[1], $parts[2], $parts[3], $parts[4])
        : 'not an Argon2id hash';
}

/**
 * @return array{LocalConnection, CountingPasswordHasher}
 */
function timedConnection(Argon2idParameters $parameters): array
{
    $clock = new FakeClock;
    $store = new FakeLocalCredentialStore($clock);
    $hasher = new CountingPasswordHasher($parameters);
    $store->bind($store->actor(), new LoginIdentifier('ada@example.org'), $hasher->hash(new Password('correct horse battery staple')));
    $hasher->forget();

    return [new LocalConnection($store, $hasher, new Issuer('https://cms.example.org'), $clock), $hasher];
}

function refusedLogin(LocalConnection $connection, string $email, string $password): LoginErrorCode
{
    $started = $connection->start();

    try {
        $connection->complete($started->pending, new SubmittedCredentials($started->pending->state->value, $email, $password));
    } catch (LoginRefused $refused) {
        return $refused->reason;
    }

    Assert::fail('The login was accepted.');
}

it('verifies an unknown email and a wrong password exactly once each, against hashes of the same parameters', function (Argon2idParameters $parameters): void {
    [$connection, $hasher] = timedConnection($parameters);

    $unknown = refusedLogin($connection, 'nobody@example.org', 'correct horse battery staple');
    $unknownVerifications = count($hasher->verified);
    $dummy = $hasher->lastVerified();
    $hasher->forget();
    $wrong = refusedLogin($connection, 'ada@example.org', 'wrong horse battery staple');
    $account = $hasher->lastVerified();

    expect($unknown)->toBe(LoginErrorCode::Rejected)
        ->and($wrong)->toBe(LoginErrorCode::Rejected)
        ->and($unknownVerifications)->toBe(1)
        ->and($hasher->verified)->toHaveCount(1)
        ->and(hashParameters($dummy))->toBe(hashParameters($account))
        ->and(hashParameters($dummy))->toBe(sprintf('argon2id m=%d t=%d p=1', $parameters->memoryKib, $parameters->time))
        ->and($dummy instanceof PasswordHash && $account instanceof PasswordHash && $dummy->equals($account))->toBeFalse();
})->with([
    'the cheapest parameters' => [new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1)],
    'other parameters' => [new Argon2idParameters(4096, 2)],
]);

it('verifies a malformed identifier and an empty password once against the dummy hash too', function (string $email, string $password): void {
    [$connection, $hasher] = timedConnection(new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1));

    expect(refusedLogin($connection, $email, $password))->toBe(LoginErrorCode::Rejected)
        ->and($hasher->verified)->toHaveCount(1)
        ->and($hasher->lastVerified()?->equals($hasher->dummy()))->toBeTrue();
})->with([
    'an identifier with white space inside' => ['ada @example.org', 'correct horse battery staple'],
    'an empty identifier' => ['', 'correct horse battery staple'],
    'an empty password' => ['ada@example.org', ''],
]);

it('refuses a password longer than the policy takes before any hashing', function (): void {
    [$connection, $hasher] = timedConnection(new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1));

    expect(refusedLogin($connection, 'ada@example.org', str_repeat('a', 1025)))->toBe(LoginErrorCode::Rejected)
        ->and(refusedLogin($connection, 'nobody@example.org', str_repeat('a', 1025)))->toBe(LoginErrorCode::Rejected)
        ->and($hasher->verified)->toBe([]);
});

it('finds the account by the email in lower case, without white space at either end', function (): void {
    [$connection, $hasher] = timedConnection(new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1));
    $started = $connection->start();

    $assertion = $connection->complete($started->pending, new SubmittedCredentials($started->pending->state->value, ' Ada@Example.ORG ', 'correct horse battery staple'));

    expect($assertion->amr[0]->value)->toBe('pwd')
        ->and($hasher->verified)->toHaveCount(1);
});

it('hashes the password again at the installation\'s parameters when the account\'s hash was made with others', function (): void {
    $clock = new FakeClock;
    $store = new FakeLocalCredentialStore($clock);
    $actor = $store->actor();
    $old = new CountingPasswordHasher(new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1));
    $store->bind($actor, new LoginIdentifier('ada@example.org'), $old->hash(new Password('correct horse battery staple')));
    $current = new CountingPasswordHasher(new Argon2idParameters(2048, 1));
    $connection = new LocalConnection($store, $current, new Issuer('https://cms.example.org'), $clock);

    $started = $connection->start();
    $connection->complete($started->pending, new SubmittedCredentials($started->pending->state->value, 'ada@example.org', 'correct horse battery staple'));
    $rehashed = $store->ofActor($actor);
    $again = $connection->start();
    $connection->complete($again->pending, new SubmittedCredentials($again->pending->state->value, 'ada@example.org', 'correct horse battery staple'));

    expect($rehashed?->version)->toBe(2)
        ->and($rehashed instanceof LocalAccount ? hashParameters($rehashed->hash) : null)->toBe('argon2id m=2048 t=1 p=1')
        ->and($current->hashed)->toBe(1);
});

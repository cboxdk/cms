<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordErrorCode;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordRefused;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;

// The password policy of the local accounts (PRD 5.16, "Lokale konti"): at least 12 characters, at
// most 1024 bytes, and not known from breaches, each refused with its catalog code and a message
// that holds nothing of the password.

function policyRefusal(PasswordPolicy $policy, string $password): ?PasswordRefused
{
    try {
        $policy->check(new Password($password));
    } catch (PasswordRefused $refused) {
        return $refused;
    }

    return null;
}

it('refuses a password of 11 characters with password_too_short and accepts one of 12', function (): void {
    $policy = new PasswordPolicy(new FakeBreachedPasswords);
    $refused = policyRefusal($policy, 'elevenchars');

    expect(mb_strlen('elevenchars'))->toBe(11)
        ->and($refused?->reason)->toBe(PasswordErrorCode::TooShort)
        ->and($refused?->code())->toBe(ErrorCode::PasswordTooShort)
        ->and($refused?->getMessage())->not->toContain('elevenchars')
        ->and(policyRefusal($policy, 'twelve chars'))->toBeNull();
});

it('counts characters, not bytes, towards the 12', function (): void {
    $policy = new PasswordPolicy(new FakeBreachedPasswords);

    expect(policyRefusal($policy, 'æøåæøåæøåæø')?->reason)->toBe(PasswordErrorCode::TooShort)
        ->and(policyRefusal($policy, 'æøåæøåæøåæøå'))->toBeNull();
});

it('refuses a password longer than 1024 bytes with password_too_long and takes one of 1024', function (): void {
    $policy = new PasswordPolicy(new FakeBreachedPasswords);

    expect(policyRefusal($policy, str_repeat('a', 1025))?->reason)->toBe(PasswordErrorCode::TooLong)
        ->and(policyRefusal($policy, str_repeat('ø', 513))?->code())->toBe(ErrorCode::PasswordTooLong)
        ->and(policyRefusal($policy, str_repeat('a', 1024)))->toBeNull();
});

it('refuses a breached password with password_breached, and asks the breach check only about a password of a valid length', function (): void {
    $breached = new FakeBreachedPasswords(new Password('Summer2026!Summer'));
    $policy = new PasswordPolicy($breached);

    $refused = policyRefusal($policy, 'Summer2026!Summer');
    policyRefusal($policy, 'short');
    policyRefusal($policy, str_repeat('a', 2000));

    expect($refused?->reason)->toBe(PasswordErrorCode::Breached)
        ->and($refused?->code())->toBe(ErrorCode::PasswordBreached)
        ->and($refused?->getMessage())->not->toContain('Summer2026')
        ->and($breached->checks())->toBe(1)
        ->and(policyRefusal($policy, 'a long and quite unusual sentence'))->toBeNull();
});

it('fails closed when the breach check cannot be made', function (): void {
    $breached = new FakeBreachedPasswords;
    $breached->goDown();

    expect(fn () => new PasswordPolicy($breached)->check(new Password('a long and quite unusual sentence')))
        ->toThrow(BreachedPasswordsUnavailable::class);
});

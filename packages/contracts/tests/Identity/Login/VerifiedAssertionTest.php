<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity\Login;

use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use DateTimeImmutable;

/*
 * A verified assertion (PRD 5.16): who logged in, when and with which factors. Its time is UTC, a
 * method or group given twice contradicts it, and no groups is not the same as an empty list.
 */

function identity(string $connection = 'google', string $issuer = 'https://accounts.google.com', string $subject = '248289761001'): IdpIdentity
{
    return new IdpIdentity(new ConnectionId($connection), new Issuer($issuer), new Subject($subject));
}

it('holds the IdP identity, the time in UTC, the methods, the context and the groups', function (): void {
    $assertion = VerifiedAssertion::of(
        identity(),
        new DateTimeImmutable('2026-10-02T14:30:00+02:00'),
        [new AuthenticationMethod('pwd'), new AuthenticationMethod('mfa')],
        new AuthenticationContext('c1'),
        [new IdpGroup('Editors')],
    );

    expect($assertion->identity()->equals(identity()))->toBeTrue()
        ->and($assertion->authTime->format(DATE_RFC3339))->toBe('2026-10-02T12:30:00+00:00')
        ->and($assertion->authenticatedWith(new AuthenticationMethod('mfa')))->toBeTrue()
        ->and($assertion->authenticatedWith(new AuthenticationMethod('hwk')))->toBeFalse()
        ->and($assertion->acr?->value)->toBe('c1')
        ->and($assertion->groups)->toHaveCount(1);
});

it('tells a connection that sends no groups from a person in none', function (): void {
    $time = new DateTimeImmutable('2026-10-02T12:00:00Z');

    expect(VerifiedAssertion::of(identity(), $time)->groups)->toBeNull()
        ->and(VerifiedAssertion::of(identity(), $time, groups: [])->groups)->toBe([])
        ->and(VerifiedAssertion::of(identity(), $time)->amr)->toBe([])
        ->and(VerifiedAssertion::of(identity(), $time)->acr)->toBeNull();
});

it('refuses a method or a group given twice', function (): void {
    $time = new DateTimeImmutable('2026-10-02T12:00:00Z');

    expect(fn (): VerifiedAssertion => VerifiedAssertion::of(identity(), $time, [new AuthenticationMethod('pwd'), new AuthenticationMethod('pwd')]))
        ->toThrow(InvalidIdentity::class, 'gives each authentication method once')
        ->and(fn (): VerifiedAssertion => VerifiedAssertion::of(identity(), $time, groups: [new IdpGroup('Editors'), new IdpGroup('Editors')]))
        ->toThrow(InvalidIdentity::class, 'gives each group once')
        ->and(VerifiedAssertion::of(identity(), $time, groups: [new IdpGroup('Editors'), new IdpGroup('editors')])->groups)->toHaveCount(2);
});

it('is another identity when the connection, the issuer or the subject differs', function (): void {
    expect(identity()->equals(identity()))->toBeTrue()
        ->and(identity()->equals(identity(connection: 'google-workspace')))->toBeFalse()
        ->and(identity()->equals(identity(issuer: 'https://accounts.google.com/')))->toBeFalse()
        ->and(identity()->equals(identity(subject: '248289761002')))->toBeFalse();
});

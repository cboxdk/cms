<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity\Login;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TenantClaim;
use Cbox\Cms\Contracts\Identity\Login\TenantId;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The values of a login (PRD 5.16): each in its form, refused with InvalidIdentity otherwise, and
 * a message that never repeats the refused value.
 */

it('takes an issuer as OpenID Connect writes it, and http only for a loopback host', function (string $value): void {
    expect((new Issuer($value))->value)->toBe($value);
})->with([
    'https://accounts.google.com',
    'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0',
    'https://id.example.org:8443/realms/staff',
    'http://localhost:8000',
    'http://cms.localhost',
    'http://127.0.0.1',
    'http://[::1]:8080/issuer',
]);

it('refuses an issuer that is not an https URL without a query or fragment', function (string $value): void {
    expect(fn (): Issuer => new Issuer($value))->toThrow(InvalidIdentity::class, 'The issuer must be an https URL');
})->with([
    'accounts.google.com',
    'http://accounts.google.com',
    'https://accounts.google.com?tenant=x',
    'https://accounts.google.com#x',
    'https://user@accounts.google.com',
    'https:///path',
    'ftp://accounts.google.com',
    'https://accounts.google.com/ path',
    '',
]);

it('refuses an issuer longer than 255 characters', function (): void {
    expect(fn (): Issuer => new Issuer('https://example.org/'.str_repeat('a', 236)))->toThrow(InvalidIdentity::class)
        ->and((new Issuer('https://example.org/'.str_repeat('a', 235)))->value)->toHaveLength(255);
});

it('compares issuers exactly, so a trailing slash is another issuer', function (): void {
    expect(new Issuer('https://accounts.google.com')->equals(new Issuer('https://accounts.google.com/')))->toBeFalse()
        ->and(new Issuer('https://accounts.google.com')->equals(new Issuer('https://accounts.google.com')))->toBeTrue();
});

it('takes each value in its form and refuses the rest without repeating it', function (callable $make, string $value, string $message): void {
    try {
        $make($value);
        throw new AssertionFailedError('The value was taken.');
    } catch (InvalidIdentity $invalid) {
        expect($invalid->getMessage())->toContain($message)
            ->and($value === '' || ! str_contains($invalid->getMessage(), $value))->toBeTrue();
    }
})->with([
    'connection with a capital' => [static fn (string $v): ConnectionId => new ConnectionId($v), 'Google', 'connection id'],
    'connection starting with a digit' => [static fn (string $v): ConnectionId => new ConnectionId($v), '1google', 'connection id'],
    'connection too long' => [static fn (string $v): ConnectionId => new ConnectionId($v), 'g'.str_repeat('o', 64), 'connection id'],
    'empty subject' => [static fn (string $v): Subject => new Subject($v), '', 'subject'],
    'subject with a space' => [static fn (string $v): Subject => new Subject($v), 'ada lovelace', 'subject'],
    'subject too long' => [static fn (string $v): Subject => new Subject($v), str_repeat('s', 256), 'subject'],
    'tenant claim with a dash' => [static fn (string $v): TenantClaim => new TenantClaim($v), 'tenant-id', 'tenant claim'],
    'tenant with a space' => [static fn (string $v): TenantId => new TenantId($v), 'acme corp', 'tenant'],
    'method with a space' => [static fn (string $v): AuthenticationMethod => new AuthenticationMethod($v), 'one time', 'authentication method'],
    'method too long' => [static fn (string $v): AuthenticationMethod => new AuthenticationMethod($v), str_repeat('m', 65), 'authentication method'],
    'context with a newline' => [static fn (string $v): AuthenticationContext => new AuthenticationContext($v), "c1\nc2", 'authentication context'],
    'group with a control character' => [static fn (string $v): IdpGroup => new IdpGroup($v), "Editors\x07", 'group'],
    'group of invalid UTF-8' => [static fn (string $v): IdpGroup => new IdpGroup($v), "Editors\xC3", 'group'],
    'group too long' => [static fn (string $v): IdpGroup => new IdpGroup($v), str_repeat('g', 256), 'group'],
]);

it('takes the values identity providers send', function (): void {
    expect((new ConnectionId('entra-acme_2'))->value)->toBe('entra-acme_2')
        ->and((new Subject('AAAAAAAAAAAAAAAAAAAAAIkzqFVrSaSaFHy782bbtaQ'))->value)->toHaveLength(43)
        ->and(new TenantClaim('hd')->equals(new TenantClaim('hd')))->toBeTrue()
        ->and(new TenantId('example.org')->equals(new TenantId('Example.org')))->toBeFalse()
        ->and(new AuthenticationMethod('hwk')->equals(new AuthenticationMethod('hwk')))->toBeTrue()
        ->and((new AuthenticationContext('urn:mace:incommon:iap:silver'))->value)->toBe('urn:mace:incommon:iap:silver')
        ->and((new IdpGroup('Rédaktører og udviklere'))->value)->toBe('Rédaktører og udviklere');
});

it('reads a tenant claim from the token only when it carries text', function (): void {
    $claims = new TokenClaims(new Issuer('https://accounts.google.com'), new Subject('1'), ['hd' => 'example.org', 'tid' => '']);

    expect($claims->claim(new TenantClaim('hd')))->toBe('example.org')
        ->and($claims->claim(new TenantClaim('tid')))->toBeNull()
        ->and($claims->claim(new TenantClaim('org')))->toBeNull();
});

it('refuses a login with a message that names the reason, and a catalog entry for every reason', function (LoginErrorCode $reason): void {
    $refused = LoginRefused::because($reason);
    $entry = ErrorCode::from($reason->value)->entry();

    expect($refused->reason)->toBe($reason)
        ->and($refused->getMessage())->toStartWith('The login was refused: ')
        ->and($entry->http)->toBe(HttpStatus::Unauthorized)
        ->and($entry->retryable)->toBeFalse();
})->with(LoginErrorCode::cases());

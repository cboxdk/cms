<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\Login\Boundary\LoginInput;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\InvalidClientAddress;
use Cbox\Cms\Identity\Tests\Login\ThrottleSecrets;

// What a login or reset form posted, read into the domain's values before an action sees it (PRD
// 5.16, GUARDRAILS 2.2): the client's IP address as a ClientAddress in its canonical text, which
// refuses an empty or invalid address, the email field as a TypedLogin that tells a field left
// empty from one that holds no login identifier, and the password as a Password or nothing.

it('holds an IP address in its canonical text, so two spellings of one address are one throttle key', function (): void {
    $short = new ClientAddress('2001:DB8::1');
    $long = new ClientAddress('2001:db8:0:0:0:0:0:1');
    $login = new LoginIdentifier('ada@example.org');

    expect($short->value)->toBe('2001:db8::1')
        ->and($short->equals($long))->toBeTrue()
        ->and(new ClientAddress('192.0.2.1')->value)->toBe('192.0.2.1')
        ->and(LoginThrottleKeys::of(ThrottleSecrets::fixed(), $login, $short)->ip)->toBe(LoginThrottleKeys::of(ThrottleSecrets::fixed(), $login, $long)->ip)
        ->and(LoginThrottleKeys::of(ThrottleSecrets::fixed(), $login, $short)->ip)->toBe(ThrottleSecrets::fixed()->hash('2001:db8::1'))
        ->and(print_r($short, true))->not->toContain('2001');
});

it('refuses an empty or invalid IP address without repeating it', function (string $text): void {
    $refusal = null;

    try {
        new ClientAddress($text);
    } catch (InvalidClientAddress $refused) {
        $refusal = $refused->getMessage();
    }

    expect($refusal)->toBe('A client address is an IPv4 or IPv6 address.')
        ->and(LoginInput::address($text))->toBeNull();
})->with([
    'empty' => [''],
    'a word' => ['localhost'],
    'with white space' => [' 192.0.2.1'],
    'out of range' => ['192.0.2.256'],
    'with a zone' => ['fe80::1%eth0'],
]);

it('reads no client address from a request that has none', function (): void {
    expect(LoginInput::address(null))->toBeNull()
        ->and(LoginInput::address(['192.0.2.1']))->toBeNull()
        ->and(LoginInput::address('192.0.2.1')?->value)->toBe('192.0.2.1');
});

it('tells an email field left empty from one that holds no login identifier', function (): void {
    $typed = LoginInput::login(' Ada@Example.ORG ');

    expect($typed->given)->toBeTrue()
        ->and($typed->identifier?->value)->toBe('ada@example.org')
        ->and(LoginInput::login('   ')->given)->toBeFalse()
        ->and(LoginInput::login(null)->given)->toBeFalse()
        ->and(LoginInput::login(['ada@example.org'])->given)->toBeFalse()
        ->and(LoginInput::login('ada lovelace@example.org')->given)->toBeTrue()
        ->and(LoginInput::login('ada lovelace@example.org')->identifier)->toBeNull()
        ->and(print_r($typed, true))->not->toContain('ada');
});

it('reads a password, and nothing for a field left empty or not text', function (): void {
    expect(LoginInput::password('correct horse battery staple'))->toBeInstanceOf(Password::class)
        ->and(LoginInput::password('correct horse battery staple')?->reveal())->toBe('correct horse battery staple')
        ->and(LoginInput::password(''))->toBeNull()
        ->and(LoginInput::password(null))->toBeNull()
        ->and(LoginInput::password(['x']))->toBeNull();
});

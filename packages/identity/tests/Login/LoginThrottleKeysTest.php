<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login;

use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\Login\Boundary\LoginInput;
use Cbox\Cms\Identity\Login\Boundary\ThrottleSecretConfig;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleSecret;
use Cbox\Cms\Identity\Login\Domain\InvalidLoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/*
 * The keys a login or reset attempt is counted under (PRD 5.16, 12.2) are keyed hashes under the
 * throttle secret, never a plain SHA-256 of the email or the IP address, which anyone who reads
 * Valkey could reverse by hashing every IPv4 address or a list of known emails.
 */

it('keys the identifier and the address with HMAC-SHA-256 under the secret', function (): void {
    $keys = LoginThrottleKeys::of(ThrottleSecrets::fixed(), LoginInput::login(' Ada@Example.org ')->identifier ?? throw new RuntimeException('The identifier is unreadable.'), new ClientAddress('192.0.2.1'));
    $secret = hash_hmac('sha256', ThrottleSecret::CONTEXT, ThrottleSecrets::APPLICATION_KEY, true);

    expect($keys->key(ThrottleScope::Identifier))->toBe(hash_hmac('sha256', 'ada@example.org', $secret))
        ->and($keys->key(ThrottleScope::Ip))->toBe(hash_hmac('sha256', '192.0.2.1', $secret))
        ->and($keys->identifier)->not->toBe(hash('sha256', 'ada@example.org'))
        ->and($keys->ip)->not->toBe(hash('sha256', '192.0.2.1'))
        ->and($keys->identifier)->toMatch('/^[0-9a-f]{64}$/')
        ->and($keys->ip)->toMatch('/^[0-9a-f]{64}$/');
});

it('gives other keys under another application key', function (): void {
    $other = LoginThrottleKeys::of(ThrottleSecret::fromApplicationKey(str_repeat('b', 32)), new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'));
    $keys = LoginThrottleKeys::of(ThrottleSecrets::fixed(), new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'));

    expect($other->identifier)->not->toBe($keys->identifier)
        ->and($other->ip)->not->toBe($keys->ip);
});

it('never shows or serialises the secret', function (): void {
    $secret = ThrottleSecrets::fixed();

    expect(print_r($secret, true))->not->toContain(hash_hmac('sha256', ThrottleSecret::CONTEXT, ThrottleSecrets::APPLICATION_KEY, true))
        ->and(fn (): string => serialize($secret))->toThrow(LogicException::class);
});

it('refuses an application key shorter than 16 bytes', function (): void {
    expect(fn (): ThrottleSecret => ThrottleSecret::fromApplicationKey(str_repeat('k', 15)))->toThrow(InvalidArgumentException::class);
});

it('reads the secret from the application key as Laravel writes it', function (): void {
    $key = random_bytes(32);
    $secret = ThrottleSecretConfig::read(new Repository(['app' => ['key' => 'base64:'.base64_encode($key)]]));

    expect(LoginThrottleKeys::of($secret, new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'))->ip)
        ->toBe(LoginThrottleKeys::of(ThrottleSecret::fromApplicationKey($key), new LoginIdentifier('ada@example.org'), new ClientAddress('192.0.2.1'))->ip);
});

it('refuses a missing, malformed or short application key', function (mixed $key): void {
    expect(fn (): ThrottleSecret => ThrottleSecretConfig::read(new Repository(['app' => ['key' => $key]])))
        ->toThrow(InvalidLoginThrottle::class, 'app.key');
})->with([
    'missing' => [null],
    'empty' => [''],
    'not base64' => ['base64:%%%'],
    'short' => ['base64:'.base64_encode('short')],
]);

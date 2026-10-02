<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity\Login;

use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\CallbackParameters;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginStarted;
use Cbox\Cms\Contracts\Identity\Login\LoginState;
use Cbox\Cms\Contracts\Identity\Login\PendingLogin;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use PHPUnit\Framework\AssertionFailedError;

/*
 * A pending login (PRD 5.16): a response belongs to it only with its connection, its flow and its
 * state, and its state and secrets are never shown when it is dumped.
 */

function pending(LoginFlow $flow = LoginFlow::Redirect): PendingLogin
{
    return new PendingLogin(new ConnectionId('google'), $flow, LoginState::fromRandomBytes(str_repeat("\x2a", 32)), ['nonce' => 'n-0123456789']);
}

it('makes a state of base64url from at least 128 random bits', function (): void {
    expect(LoginState::fromRandomBytes(str_repeat("\xff", 16))->value)->toBe('_____________________w')
        ->and(LoginState::fromRandomBytes(str_repeat("\xfb", 96))->value)->toHaveLength(128)
        ->and(fn (): LoginState => LoginState::fromRandomBytes(str_repeat("\x01", 15)))->toThrow(InvalidIdentity::class)
        ->and(fn (): LoginState => LoginState::fromRandomBytes(str_repeat("\x01", 97)))->toThrow(InvalidIdentity::class)
        ->and(fn (): LoginState => new LoginState('too-short'))->toThrow(InvalidIdentity::class)
        ->and(fn (): LoginState => new LoginState(str_repeat('a', 21).'='))->toThrow(InvalidIdentity::class);
});

it('takes a response of its connection, flow and state', function (): void {
    $pending = pending();
    $pending->check(new ConnectionId('google'), new CallbackParameters(['code' => 'c', 'state' => $pending->state->value]));

    $direct = pending(LoginFlow::Direct);
    $direct->check(new ConnectionId('google'), new SubmittedCredentials($direct->state->value, 'ada@example.org', 'secret'));

    expect($pending->secret('nonce'))->toBe('n-0123456789');
});

it('refuses a response of another state, flow or connection as a state mismatch', function (string $connection, LoginResponse $response): void {
    try {
        pending()->check(new ConnectionId($connection), $response);
        throw new AssertionFailedError('The response was taken.');
    } catch (LoginRefused $refused) {
        expect($refused->reason)->toBe(LoginErrorCode::StateMismatch);
    }
})->with(function (): array {
    $state = pending()->state->value;

    return [
        'another state' => ['google', new CallbackParameters(['code' => 'c', 'state' => strrev($state)])],
        'no state' => ['google', new CallbackParameters(['code' => 'c'])],
        'a longer state' => ['google', new CallbackParameters(['state' => $state.'A'])],
        'the other flow' => ['google', new SubmittedCredentials($state, 'ada@example.org', 'secret')],
        'another connection' => ['entra-acme', new CallbackParameters(['code' => 'c', 'state' => $state])],
    ];
});

it('refuses a secret its connection did not make', function (): void {
    expect(fn (): string => pending()->secret('code_verifier'))->toThrow(InvalidIdentity::class, 'every secret its connection made');
});

it('shows no state, secret or callback value when dumped', function (): void {
    $pending = pending();
    $credentials = new SubmittedCredentials($pending->state->value, 'ada@example.org', 'correct horse battery');
    $callback = new CallbackParameters(['code' => 'the-code', 'state' => $pending->state->value]);

    $dumped = print_r([$pending, $credentials, $callback], true);

    expect($dumped)->not->toContain($pending->state->value)
        ->and($dumped)->not->toContain('n-0123456789')
        ->and($dumped)->not->toContain('correct horse battery')
        ->and($dumped)->not->toContain('the-code')
        ->and($dumped)->toContain('ada@example.org')
        ->and($credentials->secret())->toBe('correct horse battery')
        ->and($callback->parameter('code'))->toBe('the-code')
        ->and($callback->parameter('error'))->toBeNull();
});

it('gives a redirect for the flow Redirect only', function (): void {
    expect((new LoginStarted(pending(), 'https://accounts.google.com/o/oauth2/v2/auth?state=x'))->redirectTo)->not->toBeNull()
        ->and((new LoginStarted(pending(LoginFlow::Direct)))->redirectTo)->toBeNull()
        ->and(fn (): LoginStarted => new LoginStarted(pending()))->toThrow(InvalidIdentity::class)
        ->and(fn (): LoginStarted => new LoginStarted(pending(LoginFlow::Direct), 'https://example.org'))->toThrow(InvalidIdentity::class)
        ->and(fn (): LoginStarted => new LoginStarted(pending(), '/relative'))->toThrow(InvalidIdentity::class, 'absolute http or https URL');
});

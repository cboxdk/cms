<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Cbox\Cms\Testkit\Login\FakeLoginAccount;
use Cbox\Cms\Testkit\Login\FakeLoginConnection;

// A login through an OpenID Connect connection, on the testkit's fakes. The login page starts the
// login, keeps the pending login in the server-side session and sends the browser to the
// provider; the callback completes it and gets the verified assertion the kernel finds the actor
// by (PRD 5.16).

it('completes a redirect login with the callback and gives the verified assertion', function (): void {
    $google = new ConnectionId('google');
    $issuer = new Issuer('https://accounts.google.com');
    $connection = new FakeLoginConnection($google, LoginFlow::Redirect, new FakeIssuerResolver(new IssuerPin($google, $issuer)), new FakeClock(new DateTimeImmutable('2026-10-02T09:00:00Z')));
    $ada = new FakeLoginAccount('ada@example.org', 'unused', new TokenClaims($issuer, new Subject('248289761001')), [new AuthenticationMethod('pwd')], groups: [new IdpGroup('editors')]);

    $started = $connection->start();
    $callback = $connection->respondAs($started, $ada);
    $assertion = $connection->complete($started->pending, $callback);

    expect($started->redirectTo)->toStartWith('https://accounts.google.com/authorize?')
        ->and($assertion->identity()->subject->value)->toBe('248289761001')
        ->and($assertion->authTime->format(DATE_ATOM))->toBe('2026-10-02T09:00:00+00:00')
        ->and($assertion->authenticatedWith(new AuthenticationMethod('pwd')))->toBeTrue()
        ->and($assertion->groups)->toEqual([new IdpGroup('editors')]);
});

it('refuses a callback that belongs to another login before it looks at the code', function (): void {
    $google = new ConnectionId('google');
    $issuer = new Issuer('https://accounts.google.com');
    $connection = new FakeLoginConnection($google, LoginFlow::Redirect, new FakeIssuerResolver(new IssuerPin($google, $issuer)));
    $mallory = new FakeLoginAccount('mallory@example.org', 'unused', new TokenClaims($issuer, new Subject('666')));

    $victims = $connection->start();
    $planted = $connection->respondAs($connection->start(), $mallory);

    try {
        $connection->complete($victims->pending, $planted);
        $reason = null;
    } catch (LoginRefused $refused) {
        $reason = $refused->reason;
    }

    expect($reason)->toBe(LoginErrorCode::StateMismatch);
});

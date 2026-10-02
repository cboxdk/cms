---
title: Login connection
weight: 42
description: "The LoginConnection contract: one shape for a direct credential check and a redirect flow, the pending login and its state, the VerifiedAssertion it gives, the refusals, the testkit's FakeLoginConnection and the shared suite LoginConnectionContract with its harness."
---

# Login connection

<!-- extension-point: Cbox\Cms\Contracts\Identity\Login\LoginConnection -->
<!-- extension-point: Cbox\Cms\Contracts\Identity\Login\LoginResponse -->
<!-- extension-point: Cbox\Cms\Testkit\Login\LoginConnectionHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Login\LoginConnectionContract -->

The kernel knows no particular identity provider (PRD 5.16). A login connection verifies who logged in, when and with which factors, and hands the kernel a `VerifiedAssertion`. Everything after that is the kernel's: the actor, its state, the login policy, grants and revocation. The contracts live in `Cbox\Cms\Contracts\Identity\Login` and are `#[Experimental]`: the real connections, local accounts and OpenID Connect, come with the identity module, and until then only the testkit's fake implements them.

## The contract

`Cbox\Cms\Contracts\Identity\Login\LoginConnection` has four methods:

- `id(): ConnectionId` is the connection's name in the environment's configuration, such as `local`, `google` or `entra-acme`.
- `flow(): LoginFlow` is `Direct`, where the login page takes the credentials and the connection checks them, as for a local password, or `Redirect`, where the browser goes to the identity provider and comes back to a callback, as for OpenID Connect.
- `start(): LoginStarted` starts a login. It gives a `PendingLogin`, with the connection, the flow, a fresh `LoginState` of at least 128 random bits and the secrets the connection needs later, such as a nonce and a PKCE verifier. For the flow `Redirect` it also gives `redirectTo`, the provider's URL, which carries the state.
- `complete(PendingLogin $pending, LoginResponse $response): VerifiedAssertion` completes it.

The caller keeps the pending login server-side, in the session of the browser that started it, never in the browser, and removes it from the session before it calls `complete()`, so a pending login is completed at most once.

A `LoginResponse` is what came back: `SubmittedCredentials` (the state the form carried, the identifier and the secret) for the flow `Direct`, or `CallbackParameters` (the callback's query parameters) for the flow `Redirect`. Both are read from the request as they are and hold no decision. Neither shows its secret, state or values in a dump.

`complete()` checks in this order and refuses with `LoginRefused`, whose `reason` is a `LoginErrorCode`:

| Reason | Code | When |
|---|---|---|
| `StateMismatch` | `login_state_mismatch` | the response does not belong to the pending login: another state, none, another flow, or a pending login of another connection. `PendingLogin::check()` decides it, in constant time, before any credential or token is read |
| `Rejected` | `login_rejected` | the provider or the credential check did not accept it: a wrong secret, an unknown account, an unknown code or an error from the provider |
| `IssuerMismatch`, `TenantClaimMissing`, `TenantMismatch` | `login_issuer_mismatch`, `login_tenant_claim_missing`, `login_tenant_mismatch` | the token is not from the issuer and tenant the connection is pinned to; see the [issuer resolver](issuer-resolver.md) |

The codes are in the [error reference](../../reference/errors.md). The person is told only that the login failed; the reason goes to the log and the audit.

## The verified assertion

A `VerifiedAssertion` is a final readonly class:

- `connection`, `issuer` and `subject` are the IdP identity, `identity(): IdpIdentity`. An `IdpIdentity` is the three together and points at one actor; an email address is never part of it.
- `authTime` is when the person authenticated at the provider, the `auth_time` claim, in UTC.
- `amr` lists the `AuthenticationMethod`s, each once, in the provider's order; it is empty when the provider sends none, as Google does. `authenticatedWith()` asks for one.
- `acr` is the `AuthenticationContext`, or null.
- `groups` lists the `IdpGroup`s, each once, or is null when the connection sends no groups. An empty list says the person is in none.

A method or a group given twice contradicts the assertion and is refused with `InvalidIdentity`, as is any value out of its form. `VerifiedAssertion::of()` builds one from the `IdpIdentity` an issuer resolver admitted.

## The fake: FakeLoginConnection

`Cbox\Cms\Testkit\Login\FakeLoginConnection` is a connection of either flow with an in-memory identity provider. `enrol()` gives the provider a `FakeLoginAccount`: the identifier and secret a form takes, the `TokenClaims` of the token it issues, and its methods, context and groups. `respondAs()` is what comes back when an account logs in: its credentials for the flow `Direct`, or a callback with a single-use code bound to the pending login's nonce for the flow `Redirect`. It admits the account's claims through the `IssuerResolver` it is given and takes `auth_time` from its `Clock`. This example is in the `Unit` suite:

<!-- example: examples/Unit/Login/LoginConnectionTest.php -->
```php
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
```

## Running the shared suite against a connection

Every connection runs the shared suite, the trait `Cbox\Cms\Testkit\Login\LoginConnectionContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `login(): LoginConnectionHarness`, which returns a harness for a new connection.

`LoginConnectionHarness` is the identity provider's side: `connection()` is the connection under test, `issuer()` the issuer it is pinned to, `accepted()` what comes back for a started login when a person the provider knows logs in, as an `AcceptedLogin` with what the assertion must say, and `refused()` what comes back when the provider or the check says no. The fake is its own harness. A harness for a real OpenID Connect connection drives a provider that stands in for the real one. The testkit runs the suite against the fake of each flow.

The cases cover a start in the connection's flow, a redirect that carries the state, a state of its own for every start, an accepted login and its assertion, a refused login, and every response that does not belong to the pending login. The issuer and tenant rules are the [issuer resolver's suite](issuer-resolver.md).

---
title: Back-channel logout
weight: 44
description: "The BackChannelLogoutReceiver contract: take a verified logout token of OpenID Connect Back-Channel Logout for a connection, the sessions it ends by sid or subject, the refusals, the replayed jti, the testkit's FakeBackChannelLogoutReceiver and the shared suite BackChannelLogoutContract with its harness."
---

# Back-channel logout

<!-- extension-point: Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver -->
<!-- extension-point: Cbox\Cms\Testkit\Signals\BackChannelLogoutHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Signals\BackChannelLogoutContract -->

When a person logs out at the identity provider, or the provider ends their session, it posts a logout token of OpenID Connect Back-Channel Logout 1.0 to the CMS. `Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver` decides which sessions end (PRD 5.16, "Signaler fra IdP'en"). Ending sessions is not a command: the caller removes them from Valkey and writes the authentication log, so millions of logouts never become changesets, and the next login goes to the identity provider. The contract is `#[Experimental]`; the identity module's receiver comes with the OpenID Connect implementation.

## The contract

`receive(ConnectionId $connection, LogoutToken $token): LogoutOutcome`. The endpoint first verifies the token's signature against the issuer's keys and builds a `LogoutToken` from the token alone: `iss` as an `Issuer`, `aud` as a list of `Audience`s, `iat` and `exp` (held in UTC), `jti` as a `SignalId`, the keys of `events` as `EventTypeUri`s, `sub` as a `Subject`, `sid` as an `IdpSessionId`, and whether the token carried a `nonce` claim at all. A token with no audience, an audience twice or an expiry at or before its issue is `InvalidIdentity`.

The connection's `SignalPin` names its `Issuer`, the same as its login's, and the `Audience` the CMS has there, its client id. `receive()` decides through `SignalPin::admitLogout()` at the Clock's time, so every receiver applies the same rules in the same order, and refuses with `SignalRefused`:

| Reason | Code | When |
|---|---|---|
| `IssuerMismatch` | `signal_issuer_mismatch` | `iss` is not the pinned issuer, compared exactly |
| `AudienceMismatch` | `signal_audience_mismatch` | `aud` does not list the pinned audience |
| `IssuedInFuture` | `signal_issued_in_future` | `iat` is more than `SignalPin::CLOCK_SKEW_SECONDS` (60) after now |
| `Expired` | `signal_expired` | `exp` is 60 seconds or more before now |
| `LogoutEventMissing` | `signal_logout_event_missing` | `events` lacks `http://schemas.openid.net/event/backchannel-logout` |
| `NoncePresent` | `signal_nonce_present` | the token carries a `nonce`, which a logout token never does |
| `SubjectMissing` | `signal_subject_missing` | the token names neither `sub` nor `sid` |
| `Replayed` | `signal_replayed` | the issuer sent the `jti` before |

Only then does the receiver remember the issuer and the `jti`, at least until the token expires, so a refused token never spends its `jti`, and a `jti` is unique per issuer, not across issuers. A connection without a pin throws `UnknownConnection`, a fault of the configuration. The endpoint answers 200 for an outcome and 400 for a refusal; the codes are in the [error reference](../../reference/errors.md).

A `LogoutOutcome` holds the connection, the issuer, the `jti`, the subject and the session. With a `sid` its `scope` is `LogoutScope::IdpSession`: the sessions in the set of (connection, `sid`) end, whether or not the token also named the subject. Without one it is `LogoutScope::Subject`, and every session of the actor of `identity()`, the IdP identity (connection, issuer, subject), ends.

## The fake: FakeBackChannelLogoutReceiver

`Cbox\Cms\Testkit\Signals\FakeBackChannelLogoutReceiver` takes a `Clock` and its pins, decides through the pin and remembers each admitted issuer and `jti` for as long as it lives. It is its own harness. This example is in the `Unit` suite:

<!-- example: examples/Unit/Signals/BackChannelLogoutTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Signals\Audience;
use Cbox\Cms\Contracts\Identity\Signals\EventTypeUri;
use Cbox\Cms\Contracts\Identity\Signals\IdpSessionId;
use Cbox\Cms\Contracts\Identity\Signals\LogoutOutcome;
use Cbox\Cms\Contracts\Identity\Signals\LogoutScope;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalErrorCode;
use Cbox\Cms\Contracts\Identity\Signals\SignalId;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SignalRefused;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Signals\FakeBackChannelLogoutReceiver;

// A person logs out at the identity provider, which posts a logout token for the IdP session the
// CMS session came from. The receiver ends that session's sessions; the same token posted again
// is refused, so the logout has one effect (PRD 5.16).

it('ends the sessions of an IdP session once', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-10-02T12:00:00+00:00'));
    $connection = new ConnectionId('cbox-id');
    $issuer = new Issuer('https://id.example.org');
    $receiver = new FakeBackChannelLogoutReceiver($clock, new SignalPin($connection, $issuer, new Audience('cms-client')));
    $token = new LogoutToken(
        $issuer,
        [new Audience('cms-client')],
        $clock->now(),
        $clock->now()->modify('+2 minutes'),
        new SignalId('bcl-7f3a'),
        [EventTypeUri::backChannelLogout()],
        new Subject('248289761001'),
        new IdpSessionId('08a5019c-17e1-4977-8f42-65a12843ea02'),
    );

    $outcome = $receiver->receive($connection, $token);

    expect($outcome->scope)->toBe(LogoutScope::IdpSession)
        ->and($outcome->session?->value)->toBe('08a5019c-17e1-4977-8f42-65a12843ea02')
        ->and(static fn (): LogoutOutcome => $receiver->receive($connection, $token))->toThrow(SignalRefused::class, SignalRefused::because(SignalErrorCode::Replayed)->getMessage());
});
```

## Running the shared suite against a receiver

Every receiver runs the shared suite, the trait `Cbox\Cms\Testkit\Signals\BackChannelLogoutContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `logouts(): BackChannelLogoutHarness`, and the harness has one, `receiver(Clock $clock, SignalPin ...$pins): BackChannelLogoutReceiver`, which builds a receiver on that clock with exactly those pins and an empty replay store.

The cases cover the sessions a logout ends by `sid`, by subject and by both, a replayed `jti`, which is refused so the logout has one effect, the same `jti` of another issuer, a refused token that leaves its `jti` unspent, each refusal at its boundary of the clock skew, the order of the checks and an unknown connection. The testkit holds the suite to its purpose: `packages/testkit/tests/Signals/PlantedReceiversTest.php` runs it against a receiver that forgets the `jti` and one that ignores the nonce, and each fails.

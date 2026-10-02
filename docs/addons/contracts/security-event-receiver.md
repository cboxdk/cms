---
title: Security event receiver
weight: 45
description: "The SecurityEventReceiver contract: take a verified security event of the Shared Signals Framework for a connection, CAEP session-revoked and credential-change and RISC account-disabled, account-enabled, account-purged and credential-compromise, the action each asks for, the iss_sub subject, the idempotent jti, the testkit's FakeSecurityEventReceiver and the shared suite SecurityEventReceiverContract with its harness."
---

# Security event receiver

<!-- extension-point: Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver -->
<!-- extension-point: Cbox\Cms\Contracts\Identity\Signals\SecurityEventOutcome -->
<!-- extension-point: Cbox\Cms\Testkit\Signals\SecurityEventHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Signals\SecurityEventReceiverContract -->

An identity provider that implements the OpenID Shared Signals Framework 1.0 tells the CMS when a session is revoked, a credential changes or an account is disabled, by push (RFC 8935) or poll (RFC 8936). `Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver` decides what the core does (PRD 5.16, "Signaler fra IdP'en"). The contract is `#[Experimental]`; the identity module's receiver comes with B6.

## The contract

`receive(ConnectionId $connection, SecurityEventToken $token): SecurityEventOutcome`. The endpoint or poller first verifies the token's signature and builds a `SecurityEventToken`: `iss`, `aud`, `iat` (in UTC), `jti`, the type of its one event as an `EventTypeUri` (SSF 1.0 requires exactly one, and the implementation refuses any other number before it builds the token), and the event's subject as a `SubjectIdentifier` (RFC 9493).

`receive()` decides through `SignalPin::admitEvent()` at the Clock's time, with the connection's pin of issuer and stream audience, and refuses with `SignalRefused`, in this order:

| Reason | Code | When |
|---|---|---|
| `IssuerMismatch` | `signal_issuer_mismatch` | `iss` is not the pinned issuer |
| `AudienceMismatch` | `signal_audience_mismatch` | `aud` does not list the pinned audience |
| `IssuedInFuture` | `signal_issued_in_future` | `iat` is more than 60 seconds after now; an old event is taken, since a poll may deliver it late |
| `EventUnsupported` | `signal_event_unsupported` | the event type is not a case of `SecurityEventKind` |
| `SubjectUnsupported` | `signal_subject_unsupported` | the subject is not an `iss_sub` of the pinned issuer |

The subject must be named by issuer and subject. A `SubjectIdentifier` in any other `SubjectFormat`, such as `email` or `phone_number`, holds no values at all, so an actor is never found by an email address alone. A connection without a pin throws `UnknownConnection`.

The `jti` is idempotent. The first admitted delivery of a `jti` from its issuer is a `SecurityEventApplied`: the connection, the `jti`, the `SecurityEventKind` and the IdP identity it names, with `action()`. Every later delivery is a `SecurityEventReplayed`, which the caller acknowledges and does nothing for, so a transmitter that retries never applies an event twice. A refused token never spends its `jti`.

| Event | Kind | Action |
|---|---|---|
| CAEP `session-revoked` | `SessionRevoked` | `EndSessions`: the actor's sessions end, no command |
| CAEP `credential-change` | `CredentialChange` | `EndSessions` |
| RISC `account-disabled` | `AccountDisabled` | `Deactivate`: `actor.deactivate` with the connection as source |
| RISC `account-enabled` | `AccountEnabled` | `Reactivate`: `actor.reactivate`, only when the same connection deactivated the actor |
| RISC `account-purged` | `AccountPurged` | `Deprovision`: `actor.deprovision` |
| RISC `credential-compromise` | `CredentialCompromise` | `RevokeCredentials`: `actor.credentials_revoke` |

`SignalAction::isCommand()` tells the actions that are commands, which the caller issues with the `jti` in the unit of work of the command's envelope. An IdP identity no actor has is acknowledged and does nothing.

## The fake: FakeSecurityEventReceiver

`Cbox\Cms\Testkit\Signals\FakeSecurityEventReceiver` takes a `Clock` and its pins, decides through the pin and remembers each admitted issuer and `jti` for as long as it lives. It is its own harness. This example is in the `Unit` suite:

<!-- example: examples/Unit/Signals/SecurityEventReceiverTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Signals\Audience;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventApplied;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventKind;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReplayed;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalAction;
use Cbox\Cms\Contracts\Identity\Signals\SignalId;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SubjectIdentifier;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Signals\FakeSecurityEventReceiver;

// The identity provider disables an account and pushes a RISC account-disabled event. The first
// delivery asks for actor.deactivate of the IdP identity; the transmitter retries the push, and the
// retry is acknowledged as a replay with nothing to do, because the jti is idempotent (PRD 5.16).

it('deactivates once for an account-disabled event, however often it is delivered', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-10-02T12:00:00+00:00'));
    $connection = new ConnectionId('entra-acme');
    $issuer = new Issuer('https://ssf.example.org');
    $receiver = new FakeSecurityEventReceiver($clock, new SignalPin($connection, $issuer, new Audience('https://cms.example.org/ssf')));
    $token = new SecurityEventToken(
        $issuer,
        [new Audience('https://cms.example.org/ssf')],
        $clock->now(),
        new SignalId('set-41b2'),
        SecurityEventKind::AccountDisabled->type(),
        SubjectIdentifier::issuerAndSubject($issuer, new Subject('00u-ada')),
    );

    $first = $receiver->receive($connection, $token);
    $retry = $receiver->receive($connection, $token);

    expect($first)->toBeInstanceOf(SecurityEventApplied::class)
        ->and($first instanceof SecurityEventApplied ? $first->action() : null)->toBe(SignalAction::Deactivate)
        ->and($first instanceof SecurityEventApplied ? $first->subject->subject->value : null)->toBe('00u-ada')
        ->and($retry)->toBeInstanceOf(SecurityEventReplayed::class);
});
```

## Running the shared suite against a receiver

Every receiver runs the shared suite, the trait `Cbox\Cms\Testkit\Signals\SecurityEventReceiverContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `events(): SecurityEventHarness`, and the harness has one, `receiver(Clock $clock, SignalPin ...$pins): SecurityEventReceiver`, with an empty replay store.

The cases cover the action of each kind, a replayed `jti`, which is acknowledged without an effect, the same `jti` of another issuer, a refused token that leaves its `jti` unspent, each refusal, a subject named by email, phone number, an opaque id or aliases, a subject of another issuer, a late delivery, the order of the checks and an unknown connection. `packages/testkit/tests/Signals/PlantedReceiversTest.php` runs it against a receiver that applies a replay again and one that finds the subject by email, and each fails.

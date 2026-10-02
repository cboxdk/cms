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

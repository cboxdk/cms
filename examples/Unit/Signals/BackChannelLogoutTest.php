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

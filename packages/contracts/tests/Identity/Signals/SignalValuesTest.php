<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity\Signals;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Signals\Audience;
use Cbox\Cms\Contracts\Identity\Signals\EventTypeUri;
use Cbox\Cms\Contracts\Identity\Signals\IdpSessionId;
use Cbox\Cms\Contracts\Identity\Signals\LogoutOutcome;
use Cbox\Cms\Contracts\Identity\Signals\LogoutScope;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventKind;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalAction;
use Cbox\Cms\Contracts\Identity\Signals\SignalErrorCode;
use Cbox\Cms\Contracts\Identity\Signals\SignalId;
use Cbox\Cms\Contracts\Identity\Signals\SignalRefused;
use Cbox\Cms\Contracts\Identity\Signals\SubjectFormat;
use Cbox\Cms\Contracts\Identity\Signals\SubjectIdentifier;
use DateTimeImmutable;

/*
 * The values of a lifecycle signal (PRD 5.16): each in its form, refused with InvalidIdentity
 * otherwise, with a message that never repeats the refused value, and refusal codes that are codes
 * of the error catalog.
 */

it('takes an audience, a jti and a sid of 1 to 255 visible ASCII characters', function (): void {
    expect(new Audience('cms-client')->value)->toBe('cms-client')
        ->and(new SignalId(str_repeat('j', 255))->value)->toHaveLength(255)
        ->and(new IdpSessionId('08a5019c')->equals(new IdpSessionId('08a5019c')))->toBeTrue()
        ->and(new SignalId('a')->equals(new SignalId('A')))->toBeFalse();
});

it('refuses an audience, a jti or a sid out of form without repeating it', function (string $value): void {
    foreach ([Audience::class, SignalId::class, IdpSessionId::class] as $class) {
        try {
            new $class($value);
        } catch (InvalidIdentity $refused) {
            expect($refused->getMessage())->not->toContain($value === '' ? 'never-in-a-message' : $value);

            continue;
        }

        expect($class)->toBe('refused');
    }
})->with(['', 'has space', "tab\tinside", 'æøå', 'secret-'.str_repeat('x', 249)]);

it('takes an event type that is an absolute URI', function (): void {
    expect(EventTypeUri::backChannelLogout()->value)->toBe('http://schemas.openid.net/event/backchannel-logout')
        ->and(new EventTypeUri('urn:example:event')->value)->toBe('urn:example:event');

    foreach (['', 'relative/path', 'https://example.org/a b', 'https://'.str_repeat('x', 250)] as $value) {
        expect(static fn (): EventTypeUri => new EventTypeUri($value))->toThrow(InvalidIdentity::class);
    }
});

it('maps each security event to the action PRD 5.16 gives it', function (): void {
    expect(array_map(static fn (SecurityEventKind $kind): string => $kind->action()->value, SecurityEventKind::cases()))->toBe([
        'end_sessions', 'end_sessions', 'deactivate', 'reactivate', 'deprovision', 'revoke_credentials',
    ])
        ->and(SecurityEventKind::of(new EventTypeUri('https://schemas.openid.net/secevent/risc/event-type/account-purged')))->toBe(SecurityEventKind::AccountPurged)
        ->and(SecurityEventKind::of(EventTypeUri::backChannelLogout()))->toBeNull()
        ->and(SignalAction::EndSessions->isCommand())->toBeFalse()
        ->and(SignalAction::RevokeCredentials->isCommand())->toBeTrue();
});

it('holds the values of an iss_sub subject alone', function (): void {
    $subject = SubjectIdentifier::issuerAndSubject(new Issuer('https://id.example.org'), new Subject('1001'));
    $email = SubjectIdentifier::inFormat(SubjectFormat::Email);

    expect($subject->format)->toBe(SubjectFormat::IssSub)
        ->and($subject->subject?->value)->toBe('1001')
        ->and($email->issuer)->toBeNull()
        ->and($email->subject)->toBeNull()
        ->and(static fn (): SubjectIdentifier => SubjectIdentifier::inFormat(SubjectFormat::IssSub))->toThrow(InvalidIdentity::class);
});

it('keeps a logout token\'s times in UTC and refuses one that expires before it was issued', function (): void {
    $issued = new DateTimeImmutable('2026-10-02T14:00:00+02:00');
    $token = new LogoutToken(new Issuer('https://id.example.org'), [new Audience('cms')], $issued, $issued->modify('+2 minutes'), new SignalId('j'), [EventTypeUri::backChannelLogout()], null, new IdpSessionId('s'));

    expect($token->issuedAt->format(DATE_ATOM))->toBe('2026-10-02T12:00:00+00:00')
        ->and($token->expiresAt->getTimezone()->getName())->toBe('UTC')
        ->and($token->holdsLogoutEvent())->toBeTrue()
        ->and($token->nonce)->toBeFalse()
        ->and(static fn (): LogoutToken => new LogoutToken(new Issuer('https://id.example.org'), [new Audience('cms')], $issued, $issued, new SignalId('j'), [], null, null))->toThrow(InvalidIdentity::class);
});

it('refuses a token whose audience is empty or names one twice', function (): void {
    $issuer = new Issuer('https://id.example.org');
    $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
    $subject = SubjectIdentifier::issuerAndSubject($issuer, new Subject('1001'));

    expect(static fn (): SecurityEventToken => new SecurityEventToken($issuer, [], $now, new SignalId('j'), SecurityEventKind::SessionRevoked->type(), $subject))->toThrow(InvalidIdentity::class)
        ->and(static fn (): SecurityEventToken => new SecurityEventToken($issuer, [new Audience('a'), new Audience('a')], $now, new SignalId('j'), SecurityEventKind::SessionRevoked->type(), $subject))->toThrow(InvalidIdentity::class)
        ->and(new SecurityEventToken($issuer, [new Audience('a'), new Audience('b')], $now, new SignalId('j'), SecurityEventKind::SessionRevoked->type(), $subject)->audience)->toHaveCount(2);
});

it('scopes a logout outcome by its sid, and needs a subject or a sid', function (): void {
    $connection = new ConnectionId('cbox-id');
    $issuer = new Issuer('https://id.example.org');

    expect(new LogoutOutcome($connection, $issuer, new SignalId('j'), new Subject('1001'), new IdpSessionId('s'))->scope)->toBe(LogoutScope::IdpSession)
        ->and(new LogoutOutcome($connection, $issuer, new SignalId('j'), new Subject('1001'), null)->scope)->toBe(LogoutScope::Subject)
        ->and(static fn (): LogoutOutcome => new LogoutOutcome($connection, $issuer, new SignalId('j'), null, null))->toThrow(InvalidIdentity::class);
});

it('gives every refusal a code of the error catalog, answered with 400', function (SignalErrorCode $reason): void {
    $entry = ErrorCode::from($reason->value)->entry();

    expect($entry->http)->toBe(HttpStatus::BadRequest)
        ->and($entry->retryable)->toBeFalse()
        ->and(SignalRefused::because($reason)->reason)->toBe($reason)
        ->and(SignalRefused::because($reason)->getMessage())->toStartWith('The signal was refused: ');
})->with(SignalErrorCode::cases());
